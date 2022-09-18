<?php

namespace Kt\Crm\Handlers;

use Bitrix\Crm\Communication\Type;
use Bitrix\Crm\Integrity\Duplicate;
use Bitrix\Crm\Integrity\DuplicateCommunicationCriterion;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Integration\Extranet\User;
use CCrmActivity;
use CCrmOwnerType;
use CCrmOwnerTypeAbbr;
use CTaskItem;
use Kt\Activities\ActivityEventsError;
use Kt\Crm\Contact\Contact;
use Kt\Crm\Contact\ContactCollection;
use Kt\Crm\Contact\ContactTable;
use Kt\Crm\FieldMulti;
use Kt\Crm\FieldMultiTable;
use Kt\Crm\Lead\LeadTable;
use Kt\Main\User\UserTable;
use Kt\Socialnetwork\WorkgroupTable;
use Kt\Tasks\TaskTable;
use Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest;

/**
 * Класс, содержащий обработчики событий контактов.
 */
class ContactsHandler
{
    /**
     * Обработчик события перед добавлением контакта.
     * Смотрит, является ли текущий контакт дубликатом, дополняет существующий контакт и препятствует созданию нового.
     *
     * @param array $fields Массив с полями нового контакта
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest::onBeforeCrmContactAdd test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest::addEmailLengthMoreCharacters test
     */
    public static function onBeforeCrmContactAdd(array &$fields): bool
    {
        global $APPLICATION;
        if (!$fields['FM']['EMAIL'] || !count($fields['FM']['EMAIL'])) {
            return true;
        }
        $duplicateIds = [];
        foreach ($fields['FM']['EMAIL'] as $email) {
            /** @var Duplicate $duplicate Дубликаты поиска по имени */
            $emailCriterion = new DuplicateCommunicationCriterion(Type::EMAIL_NAME, $email['VALUE']);
            $duplicate = $emailCriterion->find(CCrmOwnerType::Contact);
            if ($duplicate && count($duplicate->getEntityIDs())) {
                $duplicateIds = array_unique(array_merge($duplicateIds, $duplicate->getEntityIDs()));
            }
        }
        if ($duplicateIds) {
            /** @var ContactCollection $contacts Коллекция контактов-дубликатов */
            $contacts = ContactTable::query()
                ->addSelect('FULL_NAME')
                ->whereIn('ID', $duplicateIds)
                ->fetchCollection()
                ;
            $fields['RESULT_MESSAGE'] = Loc::getMessage('NOT_ALLOWED_TO_CREATE_DUPLICATES') . '<br>' .
                                        Duplicate::entityCountToText(count($duplicateIds))
            ;
            foreach ($contacts as $contact) {
                /** @var Contact $contact Контакт-дубликат */
                $fields['RESULT_MESSAGE'] .= '<br>' .
                                             "<a href='{$contact->requireDetailUrl()}'>" .
                                             "{$contact->requireFullName()}</a>"
                ;
            }
            $APPLICATION->ThrowException($fields['RESULT_MESSAGE']);

            return false;
        }

        //-------------------------------------------------------------------------------------------------------------
        // 2. Запрещаем создавать контакт, если длина email больше Contact::MAX_EMAIL_LENGTH  символов
        //-------------------------------------------------------------------------------------------------------------
        foreach ($fields['FM']['EMAIL'] as $email) {
            if (strlen($email['VALUE']) >= Contact::MAX_EMAIL_LENGTH) {
                $fields['RESULT_MESSAGE'] = Loc::getMessage(
                    'NOT_ALLOWED_TO_CREATE_CONTACT_WITH_EMAIL_LENGTH_MORE_CHARACTERS'
                );
                $APPLICATION->ThrowException($fields['RESULT_MESSAGE']);

                return false;
            }
        }

        return true;
    }

    /**
     * Обработчик события добавления активити.
     * Если добавляется событие и у email'а в поле "от кого" стоят несколько email-ов,
     * то ищем все контакты с этими email'ами и прикрепляем к ним активити.
     *
     * @param int   $id     ID активити
     * @param array $fields Поля
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest::testAddSimpleContactByEmail тест
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest::testAddContactByEmailIfContactNotExists test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest::testAddContactByEmailIfContactExists test
     */
    public static function onActivityAdd(int $id, array &$fields): bool
    {
        // Если активити не для контакта или не получено поле "от кого"
        if (CCrmOwnerType::Contact !== $fields['OWNER_TYPE_ID'] || !isset($fields['SETTINGS']['EMAIL_META']['from'])) {
            return true;
        }
        $emailFrom = self::getEmailsByParsing($fields['SETTINGS']['EMAIL_META']['from']);
        $emailFrom = reset($emailFrom);
        /** @var FieldMulti $crmContactBinding Связь с контактом через email */
        $crmContactBinding = FieldMultiTable::query()
            ->addSelect('ELEMENT_ID')
            ->where('ENTITY_ID', CCrmOwnerType::ContactName)
            ->where('TYPE_ID', FieldMultiTable::TYPE_ID_EMAIL)
            ->where('VALUE', $emailFrom)
            ->fetchObject()
        ;
        if ($crmContactBinding) {
            $fields['BINDINGS'][] = [
                'OWNER_ID' => $crmContactBinding->requireElementId(),
                'OWNER_TYPE_ID' => CCrmOwnerType::Contact,
            ];
            // Меняем владельца активити на существующего контакта
            CCrmActivity::ChangeOwner(
                CCrmOwnerType::Contact,
                $fields['OWNER_ID'],
                CCrmOwnerType::Contact,
                $crmContactBinding->requireElementId()
            );
            $fields['OWNER_ID'] = $crmContactBinding->requireElementId();
            CCrmActivity::SaveBindings($id, $fields['BINDINGS'], false, false);
        }

        return true;
    }

    /**
     * Обработчик события добавления активити.
     * ЕСЛИ пришло письмо от нашего клиента (экстранет пользователь)
     * и Клиент - менеджер только одной группы, то
     * создать задачу в данной группе.
     *
     * @param int   $id     ID активити
     * @param array $fields Поля
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest::onActivityAddForExtranetUser тест
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest::onActivityAddForExtranetUserNullFrom тест
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest::onActivityAddForExtranetUserNullTo тест
     * @see \Kt\UnitTests\Main\Crm\ContactsHandlerTest::testStopWordsInTitle() Unit test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ContactsHandlerTest::onActivityAddCantCreateTaskFromEmail тест
     */
    public static function onActivityAddForExtranetUser(int $id, array &$fields): bool
    {
        /** Если провайдер активити не CRM_EMAIL
         * или $fields['SETTINGS']['EMAIL_META']['from'] установлена отличным от null значением
         * или $fields['SETTINGS']['EMAIL_META']['to'] установлена отличным от null значением
         */
        if ('CRM_EMAIL' !== $fields['PROVIDER_ID']
            || !isset($fields['SETTINGS']['EMAIL_META']['from'])
            || !isset($fields['SETTINGS']['EMAIL_META']['to'])) {
            return true;
        }

        /** Проверка, что тема сообщения не содержит стоп-слова в заголовке сообщения.
         */
        Loader::includeModule('kt.main');
        $taskStopWord = Option::get('kt.main', 'task_stop_words_in_title');
        $taskName = trim(str_replace(['Re:', 'Fwd:'], '', $fields['SUBJECT']));
        if (!empty($taskStopWord) && strrpos($taskName, $taskStopWord)) {
            $fields['ACTIVITY_ERROR'] = ActivityEventsError::HAS_TITLE_STOP_WORDS;

            return true;
        }

        /** Ищем пользователя в системе с заданным email */
        $emailFrom = self::getAllEmailsByParsing($fields['SETTINGS']['EMAIL_META']['from']);
        $emailFrom = reset($emailFrom);
        if ($emailFrom) {
            $user = UserTable::query()
                ->where('EMAIL', $emailFrom)
                ->fetchObject()
            ;
        }

        /** Если пользователь не найден */
        if (!$user) {
            return true;
        }

        /** Если пользователь не экстранет */
        if (!$isExtranet = User::isExtranet($user->getId())) {
            return true;
        }

        /** Проверяем что данный пользователь является менеджером групп */
        $workgroupCollection = WorkgroupTable::getList(
            ['filter' => ['ACTIVE' => 'Y', '=UF_MANAGERS' => [$user->getId()]],
                'select' => ['ID', 'UF_CREATE_TASKS_FROM_EMAIL'],
            ]
        )->fetchCollection();

        /** Не найдена не одна группа с данным пользователем */
        if (!$workgroupCollection->count()) {
            return true;
        }

        /** Пользователь является менеджером в нескольких группах */
        if ($workgroupCollection->count() > 1) {
            return true;
        }

        /** Создаем задачу в данной группе */
        $workgroupObject = $workgroupCollection->first();

        /** Включить email коллектор (принимать задачи по email) - нет */
        if (!$workgroupObject->getUfCreateTasksFromEmail()) {
            $fields['ACTIVITY_ERROR'] = ActivityEventsError::HAS_NOT_CREATE_TASK_FROM_EMAIL;

            return true;
        }

        $workgroupId = $workgroupObject->getId();
        if (!$taskObj = TaskTable::query()
            ->where('GROUP_ID', $workgroupId)
            ->where('TITLE', $taskName)
            ->fetchObject()) {
            /** Ищем пользователя, которому ставится задача*/
            if (!$fields['SETTINGS']['EMAIL_META']['to']) {
                $emailTo = self::getAllEmailsByParsing($fields['SETTINGS']['EMAIL_META']['to']);
                $emailTo = reset($emailTo);
                if ($emailTo) {
                    $user = UserTable::query()
                        ->where('EMAIL', $emailTo)
                        ->fetchObject()
                    ;
                }
            }

            /** Если пользователя не нашли, ставим задачу руководителю проекта */
            if (!$user) {
                $responsibleUserId = $workgroupObject->getOwnerId() ?? 1;
            } else {
                $responsibleUserId = $user->getId();
            }

            /** Если заданной таски не найдено - создаем ее */
            CTaskItem::add([
                'TITLE' => $taskName,
                'GROUP_ID' => $workgroupId,
                'CREATED_BY' => 1,
                'RESPONSIBLE_ID' => $responsibleUserId,
                'DESCRIPTION' => $fields['DESCRIPTION'],
                'DESCRIPTION_IN_BBCODE' => 'N',
            ], 1);
        }

        return true;
    }

    /**
     * Получить email'ы распарсив строку.
     *
     * @param string $emails Список email'ов вида < example1@example.com >
     */
    private static function getEmailsByParsing(string $emails): array
    {
        $result = [];
        preg_match('/<(.+)>/', $emails, $result);
        array_shift($result);

        return $result;
    }

    /**
     * Получить все email'ы из строки.
     *
     * @param string $emails Строка, содержащая email
     */
    private static function getAllEmailsByParsing(string $emails): array
    {
        $pattern = "/[-a-z0-9!#$%&'*_`{|}~]+[-a-z0-9!#$%&'*_`{|}~\\.=?]*@[a-zA-Z0-9_-]+[a-zA-Z0-9\\._-]+/i";
        preg_match_all($pattern, $emails, $result);

        return array_unique(reset($result));
    }
}
