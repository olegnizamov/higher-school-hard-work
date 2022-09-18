<?php

namespace Kt\Crm\Handlers;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Integration\Disk\Rest\Attachment;
use Bitrix\Tasks\Integration\Forum\Task\Comment;
use CCrmActivity;
use CCrmActivityDirection;
use CCrmOwnerType;
use CCrmOwnerTypeAbbr;
use CIntranetInviteDialog;
use CSocNetUserToGroup;
use CTaskItem;
use CTextParser;
use Kt\Activities\ActivityEventsError;
use Kt\Crm\Company\CompanyTable;
use Kt\Crm\Contact\ContactTable;
use Kt\Crm\Deal\DealTable;
use Kt\Crm\Lead\LeadTable;
use Kt\Disk\ObjectTable;
use Kt\Main\User\User;
use Kt\Main\User\UserTable;
use Kt\Socialnetwork\UserToGroupTable;
use Kt\Socialnetwork\WorkgroupTable;
use Kt\Tasks\TaskTable;

/**
 * Класс, содержащий обработчики
 * активити-писем с квадратными скобочками.
 */
class EmailsHandler
{
    /**
     * Обработчик события добавления активити.
     * Если у активити в назчании есть []
     * то активити необходимо добавить в сущности
     * указанные в данных скобках.
     *
     * @param int   $id     ID активити
     * @param array $fields Поля
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddHasSquareBracketsOnlyProjectCode test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddHasSquareProjectCodeAndTask test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddHasSquareBracketsDeal test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddHasSquareBracketsLead test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddHasSquareBracketsContact test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddHasSquareBracketsCompany test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddNullTo test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddNullFrom test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddAddAuditors test
     * @see \Kt\UnitTests\Main\Crm\EmailsHandlerTest::testStopWordsInTitle() Unit test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddCantCreateTaskFromEmail test
     */
    public static function onActivityAdd(int $id, array &$fields)
    {
        global $DB;

        /** Если провайдер активити не CRM_EMAIL
         * или $fields['SETTINGS']['EMAIL_META']['from'] установлена отличным от null значением
         * или $fields['SETTINGS']['EMAIL_META']['to'] установлена отличным от null значением
         */

        if ('CRM_EMAIL' !== $fields['PROVIDER_ID']
            || !isset($fields['SETTINGS']['EMAIL_META']['from'])
            || !isset($fields['SETTINGS']['EMAIL_META']['to'])) {
            return true;
        }

        /** Проверяем наличие [] в теме сообщения
         * Если ничего не нашли - выходим
         */
        if (!preg_match('/\[(.*?)\]/', $fields['SUBJECT'], $arrProjectCode)) {
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

        /**  Работа с сущностями crm
         * Если это сделка.
         */
        if (preg_match('/\[[' . CCrmOwnerTypeAbbr::Deal . '][0-9]+\]/', $fields['SUBJECT'], $array)) {
            $dealId = str_replace([CCrmOwnerTypeAbbr::Deal, '[', ']'], '', reset($array));
            self::saveActivityBindingForCurrentEntity(
                $id,
                $dealId,
                DealTable::class,
                CCrmOwnerType::Deal
            );

            return true;
        }

        /** Если это Лид*/
        if (preg_match('/\[[' . CCrmOwnerTypeAbbr::Lead . '][0-9]+\]/', $fields['SUBJECT'], $array)) {
            $leadId = str_replace([CCrmOwnerTypeAbbr::Lead, '[', ']'], '', reset($array));
            self::saveActivityBindingForCurrentEntity(
                $id,
                $leadId,
                LeadTable::class,
                CCrmOwnerType::Lead
            );

            return true;
        }

        /** Если это Контакт*/
        if (preg_match('/\[[' . CCrmOwnerTypeAbbr::Contact . '][0-9]+\]/', $fields['SUBJECT'], $array)) {
            $contactId = str_replace([CCrmOwnerTypeAbbr::Contact, '[', ']'], '', reset($array));
            self::saveActivityBindingForCurrentEntity(
                $id,
                $contactId,
                ContactTable::class,
                CCrmOwnerType::Contact
            );

            return true;
        }

        /** Если это Компания*/
        if (preg_match('/\[[' . CCrmOwnerTypeAbbr::Company . ']{2}[0-9]+\]/', $fields['SUBJECT'], $array)) {
            $companyId = str_replace([CCrmOwnerTypeAbbr::Company, '[', ']'], '', reset($array));
            self::saveActivityBindingForCurrentEntity(
                $id,
                $companyId,
                CompanyTable::class,
                CCrmOwnerType::Company
            );

            return true;
        }

        /**  Работа с проектом и задачей */
        if (preg_match('/\[(.*?)+-[0-9]+\]/', $fields['SUBJECT'])) {
            $isProjectAndTask = true;
        }

        /** Выбираем 2 запись без скобок */
        $projectCode = next($arrProjectCode);

        if ($isProjectAndTask) {
            [$projectCode, $taskId] = explode('-', $projectCode);

            /** Если задачи не существует,
             * то удаляет ID задачи из темы сообщения.
             */
            if (!$taskObj = TaskTable::query()
                ->where('ID', $taskId)
                ->fetchObject()) {
                unset($taskId);
            }
        }

        /** Если нет заданной группы */
        if (!$workgroupObj = WorkgroupTable::query()
            ->where('NAME', $projectCode)
            ->addSelect('OWNER_ID')
            ->addSelect('UF_CREATE_TASKS_FROM_EMAIL')
            ->addSelect('UF_CREATE_TASKS_FROM_CRM')
            ->fetchObject()) {
            return true;
        }

        /** Включить email коллектор (принимать задачи по email) - нет */
        if (!$workgroupObj->getUfCreateTasksFromEmail()) {
            $fields['ACTIVITY_ERROR'] = ActivityEventsError::HAS_NOT_CREATE_TASK_FROM_EMAIL;

            return true;
        }

        /** Получаем crm сущности, от кого необходимо принимать задачи */
        $arrCanMakeTask = $workgroupObj->getUfCreateTasksFromCrm();
        if (empty($arrCanMakeTask)) {
            $fields['ACTIVITY_ERROR'] = ActivityEventsError::HAS_NOT_ENTITY_TO_CREATE_TASKS;

            return true;
        }

        $arrEntityId = [];
        $arrEmails = [];
        foreach ($arrCanMakeTask as $canMakeTask) {
            if (false !== strpos($canMakeTask, CCrmOwnerTypeAbbr::Lead . '_')) {
                $arrEntityId[CCrmOwnerTypeAbbr::Lead][] = str_replace(
                    CCrmOwnerTypeAbbr::Lead . '_',
                    '',
                    $canMakeTask
                );
            } elseif (false !== strpos($canMakeTask, CCrmOwnerTypeAbbr::Contact . '_')) {
                $arrEntityId[CCrmOwnerTypeAbbr::Contact][] = str_replace(
                    CCrmOwnerTypeAbbr::Contact . '_',
                    '',
                    $canMakeTask
                );
            }
        }

        if (!empty($arrEntityId[CCrmOwnerTypeAbbr::Contact])) {
            $contacts = ContactTable::query()
                ->whereIn('ID', $arrEntityId[CCrmOwnerTypeAbbr::Contact])
                ->addSelect('EMAIL')
                ->fetchCollection()
            ;
            $arrEmails = array_unique(array_merge($arrEmails, $contacts->getEmailList()));
        }

        if (!empty($arrEntityId[CCrmOwnerTypeAbbr::Lead])) {
            $leads = LeadTable::query()
                ->whereIn('ID', $arrEntityId[CCrmOwnerTypeAbbr::Lead])
                ->addSelect('EMAIL')
                ->fetchCollection()
            ;
            $arrEmails = array_unique(array_merge($arrEmails, $leads->getEmailList()));
        }
        $checkEmails = self::getAllEmailsByParsing(
            $fields['SETTINGS']['EMAIL_META']['to']
            . $fields['SETTINGS']['EMAIL_META']['bcc']
            . $fields['SETTINGS']['EMAIL_META']['from']
            . $fields['SETTINGS']['EMAIL_META']['cc']
        );

        $arrEmailsCanCreateTasks = array_intersect($arrEmails, $checkEmails);
        /** Данное сообщение не может быть создано в данной теме*/
        if (empty($arrEmailsCanCreateTasks)) {
            return true;
        }

        $isNewTask = false;
        /**  Получаем ID группы */
        $workgroupId = $workgroupObj->getId();
        if (!$taskId) {
            /** Из потенциального названия задачи удаляем код проекта,
             * Re:, Fwd: и пробелы (или другие символы) из начала и конца строки.
             */
            $taskName = trim(
                str_replace(
                    [reset($arrProjectCode), 'Re:', 'Fwd:'],
                    '',
                    $fields['SUBJECT']
                )
            );

            if (!$taskObj = TaskTable::query()
                ->where('GROUP_ID', $workgroupId)
                ->where('TITLE', $taskName)
                ->fetchObject()) {
                /** Ищем пользователя, которому ставится задача*/
                $emailTo = reset($arrEmailsCanCreateTasks);
                if ($emailTo) {
                    $user = UserTable::query()
                        ->where('EMAIL', $emailTo)
                        ->fetchObject()
                    ;
                }

                /** Если пользователя не нашли, ставим задачу руководителю проекта */
                if (!$user) {
                    $responsibleUserId = $workgroupObj->getOwnerId() ?? 1;
                } else {
                    $responsibleUserId = $user->getId();
                }

                $props = [
                    'TITLE' => $taskName,
                    'GROUP_ID' => $workgroupId,
                    'CREATED_BY' => 1,
                    'RESPONSIBLE_ID' => $responsibleUserId,
                    'DESCRIPTION' => $fields['DESCRIPTION'],
                    'DESCRIPTION_IN_BBCODE' => 'N',
                ];

                /** Если заданной таски не найдено - создаем ее */
                $taskId = CTaskItem::add($props, 1)->getId();

                if (empty($taskId)) {
                    global $APPLICATION;
                    $APPLICATION->ThrowException(
                        $APPLICATION->LAST_ERROR . ' с параметрами ' .
                        print_r($props, true)
                    );
                }

                $isNewTask = true;

                /** Получаем или создаем пользователя из email */
                $emailFrom = self::getAllEmailsByParsing($fields['SETTINGS']['EMAIL_META']['from']);
                $emailFrom = reset($emailFrom);
                /** Если email некорректно спарсился */
                if (!$emailFrom) {
                    return true;
                }
                $auditorObj = UserTable::query()
                    ->where('EMAIL', $emailFrom)
                    ->fetchObject()
                ;

                if ($auditorObj) {
                    $auditorId = $auditorObj->getId();
                } else {
                    $auditorId = CIntranetInviteDialog::RegisterUser(
                        [
                            'EMAIL' => $emailFrom,
                            'LOGIN' => $emailFrom,
                            'CONFIRM_CODE' => randString(8),
                        ],
                        's1'
                    );
                }

                /** Добавить пользователя к группе */
                CSocNetUserToGroup::add([
                    'USER_ID' => $auditorId,
                    'GROUP_ID' => $workgroupId,
                    'ROLE' => UserToGroupTable::ROLE_USER,
                    '=DATE_CREATE' => $DB->CurrentTimeFunction(),
                    '=DATE_UPDATE' => $DB->CurrentTimeFunction(),
                    'MESSAGE' => '',
                    'INITIATED_BY_TYPE' => UserToGroupTable::INITIATED_BY_GROUP,
                    'INITIATED_BY_USER_ID' => 1,
                    'SEND_MAIL' => 'N',
                ]);

                /** Добавим к задаче наблюдателя */
                $taskItemObj = new CTaskItem($taskId, $responsibleUserId);
                if ($taskItemObj && isset($auditorId)) {
                    $taskItemObj->Update(['AUDITORS' => [$auditorId]]);
                }
            } else {
                $taskId = $taskObj->getId();
            }
        }

        /** Получаем все файлы активити */
        if (!empty($fields['STORAGE_ELEMENT_IDS'])) {
            $filesCollection = ObjectTable::query()
                ->whereIn('ID', $fields['STORAGE_ELEMENT_IDS'])
                ->addSelect('FILE_ID')
                ->fetchCollection()
            ;
            foreach ($filesCollection as $element) {
                $arFilePath = $_SERVER['DOCUMENT_ROOT'] . \CFile::GetPath($element->getFileId());
                $arFile = \CFile::GetByID($element->getFileId())->Fetch();
                $fileContent = File::getFileContents($arFilePath);
                Attachment::add(
                    $taskId,
                    ['NAME' => $arFile['ORIGINAL_NAME'], 'CONTENT' => base64_encode($fileContent)],
                    [
                        'USER_ID' => 1,
                        'ENTITY_ID' => TaskTable::getUfId(),
                        'FIELD_NAME' => 'UF_TASK_WEBDAV_FILES',
                    ]
                );
            }
        }

        /** Добавляем к таске комментарий */
        if (!$isNewTask) {
            $parser = new CTextParser();
            /** добавление очистки от div, т.к стандартный класс CTextParser не умеет его очищать*/
            $textInBbcode = preg_replace('/<\/?div[^>]*\>/i', '', $fields['DESCRIPTION']);
            $textInBbcode = $parser->convertHTMLToBB($textInBbcode);

            if (!empty($textInBbcode)) {
                Comment::add(
                    $taskId,
                    [
                        'AUTHOR_ID' => 1,
                        'POST_MESSAGE' => $textInBbcode,
                        'NEW_TOPIC' => 'N',
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Обработчик события добавления активити для исходящих писем.
     *
     * @param int   $id     ID активити
     * @param array $fields Поля
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddOutgoingEmails test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::onActivityAddCallMessage test
     */
    public static function onActivityAddOutgoingEmails(int $id, array &$fields)
    {
        /** Обработка что данная активити является исходящим письмом */
        if (!self::isOutgoingEmail($fields)) {
            return true;
        }

        if (!self::checkCommunications($fields)) {
            return true;
        }

        $emailsForLead = self::getEmailsFromCommunications($fields['COMMUNICATIONS']);
        $email = reset($emailsForLead);

        if (!self::hasSquareBrackets($fields)) {
            $bindings = [];
            /** Есть контакт, то положить в контакт */
            $arrContact = self::getContactsIdByEmails($emailsForLead);
            if ($arrContact) {
                $contactId = reset($arrContact);
                $bindings[] = [
                    'OWNER_ID' => $contactId,
                    'OWNER_TYPE_ID' => CCrmOwnerType::Contact,
                ];
            }

            /** Получаем контакт */
            $arrCompany = self::getCompaniesIdByEmail($emailsForLead);
            if ($arrCompany) {
                $companyId = reset($arrCompany);
            }

            $dealId = self::getDealIdByContactOrCompany($contactId, $companyId);
            if ($dealId) {
                $bindings[] = [
                    'OWNER_ID' => $dealId,
                    'OWNER_TYPE_ID' => CCrmOwnerType::Deal,
                ];
            }

            /** Есть лид с одним из получателей и он не в финальном статусе */
            /** Фильтр получения всех лидов, по совпадеюнию по CONTACT_ID, COMPANY_ID и EMAIL*/
            $query = Query::filter()->logic('or');
            if (!empty($arrContact)) {
                $query->whereIn('CONTACT_ID', $arrContact);
            }

            if (!empty($arrCompany)) {
                $query->whereIn('COMPANY_ID', $arrCompany);
            }

            if (!empty($emailsForLead)) {
                $query->whereIn('EMAIL', $emailsForLead);
            }

            if ($leadCollection = LeadTable::query()
                ->where($query)
                ->whereNotIn('STATUS_SEMANTIC_ID', ['F', 'S'])
                ->fetchCollection()) {
                foreach ($leadCollection as $leadElement) {
                    $bindings[] = [
                        'OWNER_ID' => $leadElement->getId(),
                        'OWNER_TYPE_ID' => CCrmOwnerType::Lead,
                    ];
                }
            }

            /** Сохраняем все bindings */
            CCrmActivity::SaveBindings($id, $bindings, false, false);
        } else {
            /** Проверяем наличие параметров from и to */
            if (empty($fields['SETTINGS']['EMAIL_META']['from'])
                || empty($fields['SETTINGS']['EMAIL_META']['to'])
                || empty($fields['AUTHOR_ID'])
            ) {
                return true;
            }

            /** В случае отсутствия параметров
             * добавляем параметры $fields['SETTINGS']['EMAIL_META']['to']
             * и $fields['SETTINGS']['EMAIL_META']['from'] и вызываем метод EmailsHandler::onActivityAdd.
             */

            /** Устанавливаем from равной почтой текущего пользователя */
            $author = UserTable::getById($fields['AUTHOR_ID'])->fetchObject();
            $fields['SETTINGS']['EMAIL_META']['to'] =
                $fields['SETTINGS']['EMAIL_META']['to'] ?? $author->getEmail();

            /** Устанавливаем to равной почтой из $fields['COMMUNICATIONS'] */
            $fields['SETTINGS']['EMAIL_META']['from'] = $fields['SETTINGS']['EMAIL_META']['from'] ?? $email;

            return self::onActivityAdd($id, $fields);
        }

        return true;
    }

    /**
     * Метод заглушка, аналог метода CCrmEMail::onGetFilterListImap().
     */
    public static function onGetFilterListHandler()
    {
        return [
            'ID' => 'crm_imap',
            'NAME' => 'EmailsHandler_OnGetFilterListHandler',
            'ACTION_FUNC' => ['Kt\Crm\Handlers\EmailsHandler', 'imapEmailMessageAdd'],
            'LAZY_ATTACHMENTS' => true,
        ];
    }

    /**
     * Переопределение метода обработки сообщения для добавления активити в систему.
     *
     * @param array      $msgFields  Поля сообщения
     * @param null|array $actionVars Дополнительные параметры
     * @param null|array $error      Ошибки
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\EmailsHandlerTest::imapEmailMessageAdd test
     */
    public static function imapEmailMessageAdd(array $msgFields, array $actionVars = null, array &$error = null): bool
    {
        $result = \CCrmEMail::imapEmailMessageAdd($msgFields, $actionVars, $error);

        /** Если в основном методе мы добавили активити */
        if ($result) {
            return true;
        }

        /** Если активити не создана - создаем в этом методе */
        $msgFields['MAILBOX_PROPERTIES'] = self::getMailboxById($msgFields['MAILBOX_ID']);
        $activityFields = self::prepareActivityFields($msgFields);
        $arrEmail = self::getSendersAndRecipientsEmailsByMessage($msgFields);
        $arrOwner = self::getOwnerTypeAndIdBySenders($arrEmail['SENDERS']);

        if (empty($arrOwner)) {
            return false;
        }

        $activityFields = array_merge(
            $activityFields,
            [
                'OWNER_ID' => $arrOwner['ID'],
                'OWNER_TYPE_ID' => $arrOwner['TYPE_ID'],
                'BINDINGS' => [
                    'OWNER_ID' => $arrOwner['ID'],
                    'OWNER_TYPE_ID' => $arrOwner['TYPE_ID'],
                ],
            ]
        );

        $activityFields['COMMUNICATIONS'] =
            self::getActivityCommunicationsByEmails($activityFields, $arrEmail);

        $activityId = \CCrmActivity::add(
            $activityFields,
            false,
            false,
            ['REGISTER_SONET_EVENT' => true]
        );

        if (empty($activityId)) {
            return false;
        }

        if ($msgFields['MAILBOX_PROPERTIES']['USER_ID'] > 0
            && $activityFields['IS_INCOME']
            && 'Y' != $activityFields['COMPLETED']
        ) {
            \CCrmActivity::notify(
                $activityFields,
                \CCrmNotifierSchemeType::IncomingEmail,
                sprintf('crm_email_%u_%u', $activityFields['OWNER_TYPE_ID'], $activityFields['OWNER_ID'])
            );
        }

        return true;
    }

    /**
     * Проверяем наличие сущности в системе и добавляем у активити данную сущность.
     *
     * @param int    $activityId  Id активности
     * @param int    $entityId    Id сущности
     * @param string $entityClass Класс сущности
     * @param int    $entityType  Тип сущности
     */
    private static function saveActivityBindingForCurrentEntity(
        int $activityId,
        int $entityId,
        string $entityClass,
        int $entityType
    ): void {
        if ($entityObj = $entityClass::query()
            ->where('ID', $entityId)
            ->fetchObject()) {
            $entityId = $entityObj->getId();

            $fields['BINDINGS'][] = [
                'OWNER_ID' => $entityId,
                'OWNER_TYPE_ID' => $entityType,
            ];

            CCrmActivity::SaveBindings($activityId, $fields['BINDINGS'], false, false);
        }
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
     * Проверка, что письма исходящие.
     *
     * @param array $fields Свойства активити
     */
    private static function isOutgoingEmail(
        array $fields
    ): bool {
        return 'CRM_EMAIL' == $fields['PROVIDER_ID']
            && CCrmActivityDirection::Outgoing == $fields['DIRECTION'];
    }

    /**
     * Метод проверки, что у данного письма есть связи c crm сущностями.
     *
     * @param array $fields Свойства активити
     */
    private static function checkCommunications(
        array $fields
    ): bool {
        return is_array($fields['COMMUNICATIONS'])
            && !empty($fields['COMMUNICATIONS']);
    }

    /**
     * Метод проверки, что данное письмо содержит квадратные скобки.
     *
     * @param array $fields Свойства активити
     */
    private static function hasSquareBrackets(
        array $fields
    ): bool {
        return preg_match('/\[(.*?)\]/', $fields['SUBJECT']);
    }

    /**
     * Метод получения email из массива $fields['COMMUNICATIONS'].
     *
     * @param array $communications Массив равный $fields['COMMUNICATIONS']
     */
    private static function getEmailsFromCommunications(array $communications): array
    {
        $result = [];

        foreach ($communications as $communication) {
            if (!isset($communication['VALUE']) || 'EMAIL' !== $communication['TYPE']) {
                continue;
            }
            $result[] = $communication['VALUE'];
        }

        return $result;
    }

    /**
     * Получение массива контактов по массиву email.
     *
     * @param array $emails Массив email
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function getContactsIdByEmails(array $emails): ?array
    {
        $contactCollection = ContactTable::query()
            ->whereIn('EMAIL', $emails)
            ->fetchCollection()
        ;

        if ($contactCollection) {
            return $contactCollection->getIdList();
        }

        return null;
    }

    /**
     * Получение массива компаний по массиву email.
     *
     * @param array $emails Массив email
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function getCompaniesIdByEmail(array $emails): ?array
    {
        $companyCollection = CompanyTable::query()
            ->whereIn('EMAIL', $emails)
            ->fetchCollection()
        ;

        if ($companyCollection) {
            return $companyCollection->getIdList();
        }

        return null;
    }

    /**
     * Метод получения последней сделки по контакту или компании.
     *
     * @param null|int $contactId Id контакта
     * @param null|int $companyId Id компании
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function getDealIdByContactOrCompany(int $contactId = null, int $companyId = null): ?int
    {
        if (isset($contactId) || isset($companyId)) {
            $dealObj = DealTable::query()
                ->setLimit(1)
                ->setOrder(['ID' => 'DESC'])
                ->where(
                    Query::filter()
                        ->logic('or')
                        ->where([
                            ['CONTACT_ID', $contactId],
                            ['COMPANY_ID', $companyId],
                        ])
                )
                ->fetchObject()
            ;

            if ($dealObj) {
                return $dealObj->getId();
            }
        }

        return null;
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

    /**
     *  Получаем параметры Маилбокса по его id.
     *
     * @param int $mailboxId ID maibox
     */
    private static function getMailboxById(int $mailboxId): array
    {
        $mailboxId = isset($mailboxId) ? intval($mailboxId) : 0;
        $mailbox = \CMailBox::getById($mailboxId)->fetch();
        $mailbox['USER_ID'] = $mailbox['USER_ID'] ?: User::ADMIN_USER;
        $mailbox['__email'] = $mailbox['__email'] ?: UserTable::getById(User::ADMIN_USER)->fetchObject()->getEmail();

        return $mailbox;
    }

    /**
     * Устанавливаем переменные, необходимые для создания активти.
     *
     * @param array $msgFields Свойства сообщения
     */
    private static function prepareActivityFields(array $msgFields): array
    {
        $activityFields = [];

        $site = \CSite::getList($by = 'sort', $order = 'desc', ['DEFAULT' => 'Y', 'ACTIVE' => 'Y'])->fetch();
        $siteId = !empty($site['LID']) ? $site['LID'] : 's1';
        $userOffset = \CTimeZone::getOffset($msgFields['MAILBOX_PROPERTIES']['USER_ID']);
        $currentUserOffset = \CTimeZone::getOffset();
        $nowTimestamp = time();

        return [
            'SUBJECT' => trim($msgFields['SUBJECT']) ?: '',
            'IS_INCOME' => empty($msgFields['IS_OUTCOME']),
            'IS_UNSEEN' => empty($msgFields['IS_SEEN']),
            'TYPE_ID' => \CCrmActivityType::Email,
            'ASSOCIATED_ENTITY_ID' => 0,
            'PARENT_ID' => 0,
            'START_TIME' => convertTimeStamp($nowTimestamp + $currentUserOffset, 'FULL', $siteId),
            'END_TIME' => convertTimeStamp(strtotime('tomorrow') + $currentUserOffset - $userOffset, 'FULL', $siteId),
            'COMPLETED' => empty($msgFields['IS_SEEN']) ? 'Y' : 'N',
            'AUTHOR_ID' => $msgFields['MAILBOX_PROPERTIES']['USER_ID'],
            'RESPONSIBLE_ID' => $msgFields['MAILBOX_PROPERTIES']['USER_ID'],
            'PRIORITY' => \CCrmActivityPriority::Medium,
            'DESCRIPTION' => isset($msgFields['BODY_HTML']) ? $msgFields['BODY_HTML'] : '',
            'DESCRIPTION_TYPE' => \CCrmContentType::Html,
            'DIRECTION' => !empty($activityFields['IS_INCOME']) ?
                    \CCrmActivityDirection::Incoming : \CCrmActivityDirection::Outgoing,
            'LOCATION' => '',
            'NOTIFY_TYPE' => \CCrmActivityNotifyType::None,
            'SETTINGS' => [
                'EMAIL_META' => [
                    '__email' => $msgFields['MAILBOX_PROPERTIES']['__email'],
                    'from' => isset($msgFields['FIELD_FROM']) ? $msgFields['FIELD_FROM'] : '',
                    'replyTo' => isset($msgFields['FIELD_REPLY_TO']) ? $msgFields['FIELD_REPLY_TO'] : '',
                    'to' => isset($msgFields['FIELD_TO']) ? $msgFields['FIELD_TO'] : '',
                    'cc' => isset($msgFields['FIELD_CC']) ? $msgFields['FIELD_CC'] : '',
                    'bcc' => isset($msgFields['FIELD_BCC']) ? $msgFields['FIELD_BCC'] : '',
                ],
            ],
            'UF_MAIL_MESSAGE' => isset($msgFields['ID']) ? intval($msgFields['ID']) : 0,
        ];
    }

    /**
     * Получаем email отправителей и получателей у сообщения.
     *
     * @param array $msgFields Свойства сообщения
     */
    private static function getSendersAndRecipientsEmailsByMessage(array $msgFields): array
    {
        $replyTo = isset($msgFields['FIELD_REPLY_TO']) ? $msgFields['FIELD_REPLY_TO'] : '';
        $to = isset($msgFields['FIELD_TO']) ? $msgFields['FIELD_TO'] : '';
        $cc = isset($msgFields['FIELD_CC']) ? $msgFields['FIELD_CC'] : '';
        $bcc = isset($msgFields['FIELD_BCC']) ? $msgFields['FIELD_BCC'] : '';
        $from = isset($msgFields['FIELD_FROM']) ? $msgFields['FIELD_FROM'] : '';

        $arrEmail['SENDERS'] = [];
        foreach (array_merge(
            explode(',', $replyTo),
            explode(',', $from)
        ) as $item) {
            if (trim($item)) {
                $address = new \Bitrix\Main\Mail\Address($item);
                if ($address->validate() && !in_array($address->getEmail(), $arrEmail['SENDERS'])) {
                    $arrEmail['SENDERS'][] = $address->getEmail();
                }
            }
        }

        $arrEmail['RECIPIENT'] = [];
        foreach (array_merge(
            explode(',', $to),
            explode(',', $cc),
            explode(',', $bcc)
        ) as $item) {
            if (trim($item)) {
                $address = new \Bitrix\Main\Mail\Address($item);
                if ($address->validate() && !in_array($address->getEmail(), $arrEmail['RECIPIENT'])) {
                    $arrEmail['RECIPIENT'][] = $address->getEmail();
                }
            }
        }

        return $arrEmail;
    }

    /**
     * Получаем CRM сущность к которой привязана данное сообщение.
     *
     * @param array $arrSender Массив email отправителей
     */
    private static function getOwnerTypeAndIdBySenders($arrSender): array
    {
        $arrOwner = [];
        $knownTypes = [
            CCrmOwnerType::Company => CompanyTable::class,
            CCrmOwnerType::Contact => ContactTable::class,
            CCrmOwnerType::Lead => LeadTable::class,
        ];

        foreach ($knownTypes as $key => $entityClass) {
            $entityObj = $entityClass::query()
                ->whereIn('EMAIL', $arrSender)
                ->fetchObject()
            ;
            if ($entityObj) {
                $arrOwner['ID'] = $entityObj->getId();
                $arrOwner['TYPE_ID'] = $key;
            }
        }

        return $arrOwner;
    }

    /**
     * Получение коммуникаций для Активити.
     *
     * @param array $activityFields Поля активити
     * @param array $arrEmail       Массив email
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function getActivityCommunicationsByEmails(array $activityFields, array $arrEmail): array
    {
        $arrCommunication = [];

        if (!empty($activityFields['IS_INCOME'] ? $arrEmail['SENDERS'] : $arrEmail['RECIPIENT'])) {
            $subfilter = [
                'LOGIC' => 'OR',
            ];

            foreach ($activityFields['BINDINGS'] as $item) {
                $subfilter[] = [
                    '=ENTITY_ID' => \CCrmOwnerType::resolveName($item['OWNER_TYPE_ID']),
                    '=ELEMENT_ID' => $item['OWNER_ID'],
                ];
            }

            $res = \Bitrix\Crm\FieldMultiTable::getList([
                'select' => ['ENTITY_ID', 'ELEMENT_ID', 'VALUE'],
                'group' => ['ENTITY_ID', 'ELEMENT_ID', 'VALUE'],
                'filter' => [
                    $subfilter,
                    '=TYPE_ID' => 'EMAIL',
                    '@VALUE' => $activityFields['IS_INCOME'] ? $arrEmail['SENDERS'] : $arrEmail['RECIPIENT'],
                ],
            ]);

            while ($item = $res->fetch()) {
                $arrCommunication[] = [
                    'ENTITY_TYPE_ID' => \CCrmOwnerType::resolveId($item['ENTITY_ID']),
                    'ENTITY_ID' => $item['ELEMENT_ID'],
                    'VALUE' => $item['VALUE'],
                    'TYPE' => 'EMAIL',
                ];
            }
        }

        return $arrCommunication;
    }
}
