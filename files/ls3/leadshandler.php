<?php

namespace Kt\Crm\Handlers;

use Bex\Monolog\Handler\BitrixHandler;
use Bitrix\Crm\Communication\Type;
use Bitrix\Crm\Integrity\Duplicate;
use Bitrix\Crm\Integrity\DuplicateCommunicationCriterion;
use Bitrix\Iblock\ORM\Query;
use Bitrix\Main\Localization\Loc;
use CCrmActivity;
use CCrmLead;
use CCrmOwnerType;
use CCrmOwnerTypeAbbr;
use Kt\Crm\Company\Company;
use Kt\Crm\Company\CompanyTable;
use Kt\Crm\Contact\Contact;
use Kt\Crm\Contact\ContactTable;
use Kt\Crm\Deal\Deal;
use Kt\Crm\Deal\DealTable;
use Kt\Crm\FieldMulti;
use Kt\Crm\FieldMultiTable;
use Kt\Crm\Lead\Lead;
use Kt\Crm\Lead\LeadCollection;
use Kt\Crm\Lead\LeadTable;
use Kt\Iblock\Lists\Agreements\Agreement;
use Kt\Iblock\Lists\Agreements\ElementAgreementsTable;
use Monolog\Logger;

/**
 * Класс, содержащий обработчики событий лидов.
 */
class LeadsHandler
{
    /**
     * Обработчик события перед добавлением лида.
     * 1. Смотрит, является ли добавляемый лид дубликатом. Препятствует созданию дубликата.
     * 2. Ищет контакт с аналогичным email-ом.
     *  Если контакт найден, а у компании контакта есть договора в списках "Договора"
     *  (https://crm.kt-team.de/services/lists/80/view/0/?list_section_id=),
     *  то запрещает создавать лид.
     *
     * @param array $fields Массив с полями нового лида
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\LeadsHandlerTest::onBeforeCrmLeadAdd test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\LeadsHandlerTest::onBeforeCrmLeadAddEmailLengthMoreCharacters test
     */
    public static function onBeforeCrmLeadAdd(array &$fields): bool
    {
        global $APPLICATION;
        if (!$fields['FM']['EMAIL'] || !count($fields['FM']['EMAIL'])) {
            return true;
        }
        //--------------------------------------------------
        // 1. Препятствуем созданию дубликата
        //--------------------------------------------------
        $duplicateIds = [];
        foreach ($fields['FM']['EMAIL'] as $email) {
            /** @var Duplicate $duplicate Дубликаты поиска по имени */
            $emailCriterion = new DuplicateCommunicationCriterion(Type::EMAIL_NAME, $email['VALUE']);
            $duplicate = $emailCriterion->find(CCrmOwnerType::Lead);
            if ($duplicate && count($duplicate->getEntityIDs())) {
                $duplicateIds = array_unique(array_merge($duplicateIds, $duplicate->getEntityIDs()));
            }
        }
        if ($duplicateIds) {
            /** @var LeadCollection $leads Коллекция лидов-дубликатов */
            $leads = LeadTable::query()
                ->addSelect('NAME')
                ->whereIn('ID', $duplicateIds)
                ->fetchCollection()
                ;
            $fields['RESULT_MESSAGE'] = Loc::getMessage('NOT_ALLOWED_TO_CREATE_DUPLICATES') . '<br>' .
                                        Duplicate::entityCountToText(count($duplicateIds))
            ;
            foreach ($leads as $lead) {
                /** @var Lead $lead лид-дубликат */
                $fields['RESULT_MESSAGE'] .= '<br>' .
                                             "<a href='{$lead->requireDetailUrl()}'>" .
                                             "{$lead->requireName()}</a>"
                ;
            }
            $APPLICATION->ThrowException($fields['RESULT_MESSAGE']);

            return false;
        }

        //-------------------------------------------------------------------------------------------------------------
        // 2. Ищем id контакта и компанию контакта по email. Если у компании есть договора, запрещаем создавать лид.
        //-------------------------------------------------------------------------------------------------------------
        foreach ($fields['FM']['EMAIL'] as $email) {
            $email = $email['VALUE'];
            // Ищем контакт с данным email-ом
            /** @var FieldMulti $crmContactBinding Связь с контактом через email */
            $crmContactBinding = FieldMultiTable::query()
                ->addSelect('ELEMENT_ID')
                ->where('ENTITY_ID', CCrmOwnerType::ContactName)
                ->where('TYPE_ID', FieldMultiTable::TYPE_ID_EMAIL)
                ->where('VALUE', $email)
                ->fetchObject()
            ;
            if (!$crmContactBinding) {
                continue;
            }
            $crmContactId = $crmContactBinding->requireElementId();
            // Связываем лид и контакт
            /** @var Contact $crmContact Контакт */
            $crmContact = ContactTable::getById($crmContactId)->fetchObject();
            if (!$crmContact) {
                continue;
            }
            // Ищем компанию у контакта, заполняем в лид
            if ($crmContact->requireCompanyId()) {
                /** @var Company $crmCompany Компания контакта */
                $crmCompany = CompanyTable::getById($crmContact->requireCompanyId())->fetchObject();
                if (!$crmCompany) {
                    continue;
                }
                // Получаем договора
                /** @var Agreement $agreement Договор с компанией контакта */
                $agreement = (new Query(ElementAgreementsTable::getEntity()))
                    ->setFilter(['COUNTERPARTY.VALUE' => $crmCompany->requireId()]) // PROPERTY_242 - "Контрагент"
                    ->fetchObject()
                ;
                if ($agreement) {
                    $fields['RESULT_MESSAGE'] = Loc::getMessage(
                        'NOT_ALLOWED_TO_CREATE_LEAD_WHEN_AGREEMENT_EXISTS',
                        [
                            '#CONTACT_ID#' => $crmContact->requireId(),
                            '#COMPANY_ID#' => $crmCompany->requireId(),
                            '#COMPANY_TITLE#' => $crmCompany->requireTitle(),
                            '#AGREEMENT_ID#' => $agreement->requireId(),
                        ]
                    );
                    $APPLICATION->ThrowException($fields['RESULT_MESSAGE']);

                    return false;
                }
            }
        }

        //-------------------------------------------------------------------------------------------------------------
        // 3. Запрещаем создавать лид, если длина email больше Lead::MAX_EMAIL_LENGTH символов
        //-------------------------------------------------------------------------------------------------------------
        foreach ($fields['FM']['EMAIL'] as $email) {
            if (strlen($email['VALUE']) >= Lead::MAX_EMAIL_LENGTH) {
                $fields['RESULT_MESSAGE'] = Loc::getMessage(
                    'NOT_ALLOWED_TO_CREATE_LEAD_WITH_EMAIL_LENGTH_MORE_CHARACTERS'
                );
                $APPLICATION->ThrowException($fields['RESULT_MESSAGE']);

                return false;
            }
        }

        return true;
    }

    /**
     * Обработчик события после добавлением лида,
     * который при создании лида проверяет есть ли там [L***] и если нет, добавляет номер лида в название.
     *
     * @param array $fields Массив с полями нового лида
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\LeadsHandlerTest::OnAfterCrmLeadAddIncorrectName test
     */
    public static function onAfterCrmLeadAddIncorrectName(array &$fields): bool
    {
        /** Проверяем наличие [] в названии лида, если нашли - выходим */
        if (preg_match('/\[(.*?)\]/', $fields['TITLE'])) {
            return true;
        }

        $oLead = new CCrmLead();
        $arFields = [
            'TITLE' => $fields['TITLE'] . ' [' . CCrmOwnerTypeAbbr::Lead . $fields['ID'] . ']',
        ];
        $oLead->Update($fields['ID'], $arFields);

        return true;
    }

    /**
     * Обработчик события после добавления лида.
     * 1. Ищет контакт с аналогичным email-ом. Если контакт найден, связывает лид и контакт,
     *  а также подставляет компанию контакта в лид.
     *
     * @param array $fields Массив с полями нового лида
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\LeadsHandlerTest::onAfterCrmLeadAdd test
     */
    public static function onAfterCrmLeadAdd(array &$fields): bool
    {
        try {
            if (!$fields['FM']['EMAIL'] || !count($fields['FM']['EMAIL'])) {
                return true;
            }

            $crmLeadId = $fields['ID'];

            //-------------------------------------------------------------------------------------------------------------
            // 1. Подставляем id контакта и компанию контакта в лид.
            // Если у компании есть договора, запрещаем создавать лид.
            //-------------------------------------------------------------------------------------------------------------
            foreach ($fields['FM']['EMAIL'] as $email) {
                $email = $email['VALUE'];
                // Ищем контакт с данным email-ом
                /** @var FieldMulti $crmContactBinding Связь с контактом через email */
                $crmContactBinding = FieldMultiTable::query()
                    ->addSelect('ELEMENT_ID')
                    ->where('ENTITY_ID', CCrmOwnerType::ContactName)
                    ->where('TYPE_ID', FieldMultiTable::TYPE_ID_EMAIL)
                    ->where('VALUE', $email)
                    ->fetchObject()
                ;
                if (!$crmContactBinding) {
                    continue;
                }
                $crmContactId = $crmContactBinding->requireElementId();
                // Связываем лид и контакт
                /** @var Contact $crmContact Контакт */
                $crmContact = ContactTable::getById($crmContactId)->fetchObject();
                /** @var Lead $crmLead Лид */
                $crmLead = LeadTable::getById($crmLeadId)->fetchObject();
                if (!$crmContact || !$crmLead) {
                    return true;
                }
                // Заполняем Id контакта в лид
                $arFields = [
                    'CONTACT_ID' => $crmContactId,
                ];
                // Ищем компанию у контакта, заполняем в лид
                if ($crmContact->requireCompanyId()) {
                    $arFields['COMPANY_ID'] = $crmContact->requireCompanyId();
                }
                $crmLeadObject = new CCrmLead();
                // Сохраняем лид
                /** @var bool $saveResult Результат сохранения */
                $saveResult = $crmLeadObject->Update($crmLeadId, $arFields);
                if (!$saveResult) {
                    $eventName = 'ERROR_WHILE_LINKING_LEAD_AND_CONTACT_AFTER_LEAD_CREATE';
                    $level = Logger::WARNING;
                } else {
                    $eventName = 'SUCCESS_WHILE_LINKING_LEAD_AND_CONTACT_AFTER_LEAD_CREATE';
                    $level = Logger::INFO;
                }
                $message = Loc::getMessage(
                    $eventName,
                    [
                        '#EMAIL#' => $email,
                        '#LEAD_ID#' => $crmLeadId,
                        '#CONTACT_ID#' => $crmContactId,
                        '#ERROR_MESSAGE#' => $crmLeadObject->LAST_ERROR,
                    ]
                );
                $logger = new Logger(
                    self::class,
                    [new BitrixHandler(
                        $eventName,
                        'kt.crm'
                    )]
                );
                $logger->log($level, $message);
            }
        } catch (\Throwable $e) {
            $logger = new Logger(
                self::class,
                [new BitrixHandler(
                    'EXCEPTION_WHILE_LINKING_LEAD_AND_CONTACT_AFTER_LEAD_CREATE',
                    'kt.crm'
                )]
            );
            $logger->warning(Loc::getMessage(
                'EXCEPTION_WHILE_LINKING_LEAD_AND_CONTACT_AFTER_LEAD_CREATE',
                [
                    '#EMAIL#' => $email,
                    '#LEAD_ID#' => $crmLeadId,
                    '#ERROR_MESSAGE#' => $e->getMessage(),
                    '#STACK_TRACE#' => $e->getTraceAsString(),
                ]
            ));
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
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\LeadsHandlerTest::testAddSimpleLeadByEmail тест
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\LeadsHandlerTest::testAddLeadByEmailIfLeadNotExists test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\LeadsHandlerTest::testAddLeadByEmailIfLeadExists test
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\LeadsHandlerTest::testOnCallActivityAdd() test
     */
    public static function onActivityAdd(int $id, array &$fields): bool
    {
        /**
         * Удаление дубля исходящего звонка от пользователя с id 1.
         */
        if ((CCrmOwnerType::Lead === $fields['OWNER_TYPE_ID'] || CCrmOwnerType::Lead === $fields['ENTITY_ID'])
            && (
                1 === intval($fields['RESPONSIBLE_ID'])
                && 'VOXIMPLANT_CALL' == $fields['PROVIDER_ID']
                && 'CALL' == $fields['PROVIDER_TYPE_ID']
                && CCrmActivity::GetByID($id)
            )
        ) {
            CCrmActivity::Delete($id);

            return false;
        }
        // Если активити не для лида или не получено поле "от кого"
        if (CCrmOwnerType::Lead !== $fields['OWNER_TYPE_ID'] || !isset($fields['SETTINGS']['EMAIL_META']['from'])) {
            return true;
        }
        $emailFrom = self::getEmailsByParsing($fields['SETTINGS']['EMAIL_META']['from']);
        $emailFrom = reset($emailFrom);
        /** @var FieldMulti $crmLeadBinding Связь с контактом через email */
        $crmLeadBinding = FieldMultiTable::query()
            ->addSelect('ELEMENT_ID')
            ->where('ENTITY_ID', CCrmOwnerType::LeadName)
            ->where('TYPE_ID', FieldMultiTable::TYPE_ID_EMAIL)
            ->where('VALUE', $emailFrom)
            ->fetchObject()
        ;
        if ($crmLeadBinding) {
            $fields['BINDINGS'][] = [
                'OWNER_ID' => $crmLeadBinding->requireElementId(),
                'OWNER_TYPE_ID' => CCrmOwnerType::Lead,
            ];
            // Меняем владельца активити на существующего контакта
            CCrmActivity::ChangeOwner(
                CCrmOwnerType::Lead,
                $fields['OWNER_ID'],
                CCrmOwnerType::Lead,
                $crmLeadBinding->requireElementId()
            );
            $fields['OWNER_ID'] = $crmLeadBinding->requireElementId();
            CCrmActivity::SaveBindings($id, $fields['BINDINGS'], false, false);
        }

        return true;
    }

    /**
     * Если приходит письмо от лида и для этого лида создана сделка,
     * то необходимо это письмо прикреплять ко всем сделкам и контакту этого лида.
     *
     * @param int   $id     ID активити
     * @param array $fields Поля
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\LeadsHandlerTest::testOnActivityAddBindToDeals test
     */
    public static function onActivityAddBindToDeals(int $id, array &$fields): bool
    {
        // Если активити не для лида или не получено поле "от кого"
        if (CCrmOwnerType::Lead !== $fields['OWNER_TYPE_ID'] || !isset($fields['SETTINGS']['EMAIL_META']['from'])) {
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
            /** @var null|Deal $deals Сделки, привязанные к контакту */
            $deals = DealTable::query()
                ->where('CONTACT_ID', $crmContactBinding->requireElementId())
                ->fetchCollection()
            ;
            /** @var Deal $deal Сделка */
            foreach ($deals as $deal) {
                $fields['BINDINGS'][] = [
                    'OWNER_ID' => $deal->requireId(),
                    'OWNER_TYPE_ID' => CCrmOwnerType::Deal,
                ];
            }

            $fields['BINDINGS'][] = [
                'OWNER_ID' => $crmContactBinding->requireElementId(),
                'OWNER_TYPE_ID' => CCrmOwnerType::Contact,
            ];
            CCrmActivity::SaveBindings($id, $fields['BINDINGS'], false, false);
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
}
