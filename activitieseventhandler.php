<?php

namespace Kt\Crm\Handlers;

use Bex\Monolog\Handler\BitrixHandler;
use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\Integration\Socialnetwork\Livefeed\CrmActivity;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Socialnetwork\WorkgroupFavoritesTable;
use Bitrix\Tasks\Internals\Task\CheckListTreeTable;
use CCrmLead;
use CCrmOwnerType;
use Kt\Crm\Contact\Contact;
use Kt\Crm\Contact\ContactTable;
use Kt\Crm\Deal\DealTable;
use Kt\Crm\FieldMulti;
use Kt\Crm\FieldMultiTable;
use Kt\Crm\Lead\Lead;
use Kt\Crm\Lead\LeadTable;
use Kt\Iblock\Lists\CallMeetingWithClient\CallMeetingWithClient;
use Kt\Iblock\Lists\CallMeetingWithClient\ElementCallMeetingWithClientTable;
use Kt\Main\User\UserTable;
use Kt\Socialnetwork\UserToGroupTable;
use Kt\Socialnetwork\WorkgroupTable;
use Monolog\Logger;

/**
 * Класс, содержащий общие обработчики событий активити для всех типов сущностей (лиды и контакты).
 */
class ActivitiesHandler
{
    /**
     * Обработчик события добавления дела (активити) - OnActivityAdd.
     * Связывает лид и контакт по email-у из активити.
     * Если у контакта есть компания, она тоже устанавливается в лид.
     *
     * Если добавляется событие и в поле "от кого" стоят хотя бы один email,
     * то ищем все контакты и лиды с этими email'ами и прикрепляем контакт в лид (связываем контакты и лиды).
     *
     * @param int   $id     ID активити
     * @param array $fields Поля
     *
     * @see Документация onActivityAdd https://dev.1c-bitrix.ru/api_help/crm/crm_events.php
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ActivitiesHandlerTest::testOnActivityAddLinkContactAndLead Тест
     */
    public static function onActivityAddLinkContactAndLead(int $id, array &$fields): bool
    {
        /**
         * Если событие создаётся для лида.
         * Условие проверяе что активити создаётся именно руками а не автоматически по связи события календаря и crm
         * (Context::getCurrent()->getRequest()->get('ajax_action') == 'ACTIVITY_SAVE')
         */

        if (
            (Context::getCurrent()->getRequest()->get('ajax_action') == 'ACTIVITY_SAVE') &&
            ($fields['COMPLETED'] != 'Y') &&
            ($fields['PROVIDER_TYPE_ID'] == 'CALL' || $fields['PROVIDER_TYPE_ID'] == 'MEETING') &&
            ((int) $fields['TYPE_ID'] === \CCrmActivityType::Call ||
                (int) $fields['TYPE_ID'] === \CCrmActivityType::Meeting) &&
            (int) $fields['OWNER_TYPE_ID'] === \CCrmOwnerType::Lead
        ) {
            $arFields = [
                'NAME' => $fields['SUBJECT'],
                'OWNER_ID' => $fields['RESPONSIBLE_ID'],
                'CAL_TYPE' => 'user',
                'DESCRIPTION' => $fields['DESCRIPTION'],
                'MEETING_HOST' => $fields['RESPONSIBLE_ID'],
                'LOCATION' => $fields['LOCATION'],
                'IS_MEETING' => true,
                'RRULE' => false,
                'ATTENDEES_CODES' => ['U_' . $fields['RESPONSIBLE_ID']],
                'DATE_FROM' => $fields['START_TIME'],
                'DATE_TO' => $fields['END_TIME'],
            ];
            $eventFields = [
                'arFields' => $arFields,
                'UF' => [
                    'UF_CRM_CAL_EVENT' => ['L' . $fields['OWNER_ID']],
                ],
                'autoDetectSection' => true,
                'autoCreateSection' => true
            ];
            $eventId = \CCalendar::SaveEvent($eventFields);
            $arParams = [
                'eventId' => $eventId,
                'userId' => \CCalendar::GetCurUserId(true),
                'viewPath' => '',
                'calendarType' => 'user',
                'ownerId' => $fields['AUTHOR_ID'],
            ];
            /**
             * Значение в секундах еденицы измерения времени оповещения, при типе 1 и по умолчанию минуты.
             */
            $notifySize = 60;
            switch ($fields['NOTIFY_TYPE']) {
                /**
                 * Для часоов.
                 */
                case 2:
                    $notifySize = 3600;
                    break;
                /**
                 * Для дней.
                 */
                case 3:
                    $notifySize = 86400;
                    break;
            }

            $reminder = $fields['NOTIFY_VALUE'] * $notifySize;
            /**
             * Расчитываем время оповещения.
             */
            if ($reminder > 0) {
                $remindTime = date('d.m.Y H:i:s', strtotime($fields['START_TIME'] . " - $reminder second"));
            } else {
                $remindTime = $fields['START_TIME'];
            }
            /**
             * Добавляем оповещение.
             */
            \CCalendarReminder::AddAgent($remindTime, $arParams);

            $ktSalesGroup = WorkgroupTable::getList([
                'filter' => ['UF_PROJECT_NAME' => 'KT.SALES']
            ])->fetchObject();

            if ($ktSalesGroup) {
                $eventFields['arFields']['OWNER_ID'] = $ktSalesGroup->getId();
                $eventFields['arFields']['CAL_TYPE' ] = 'group';
                \CCalendar::SaveEvent($eventFields);
            }
        } elseif (
            (Context::getCurrent()->getRequest()->get('ajax_action') == 'ACTIVITY_DONE') &&
            ($fields['COMPLETED'] != 'Y') && (int) $fields['OWNER_TYPE_ID'] !== \CCrmOwnerType::Lead &&
            ($fields['PROVIDER_TYPE_ID'] == 'CALL' || $fields['PROVIDER_TYPE_ID'] == 'MEETING') &&
            ((int) $fields['TYPE_ID'] === \CCrmActivityType::Call
                || (int) $fields['TYPE_ID'] === \CCrmActivityType::Meeting)
        ) {
            $projectId = false;
            $clientIds = false;

            $contactRefCollection = ContactCompanyTable::getList([
                'filter' => ['COMPANY_ID' => $fields['OWNER_ID']],
            ])->fetchCollection();
            $clientIds = $contactRefCollection->getContactIdList();

            /**
             * По умолчанию устанавливаем проект KT.SALES как наиболее актуальный для таких звонков или встреч.
             */
            $ktSalesGroup = WorkgroupTable::getList([
                'filter' => ['UF_PROJECT_NAME' => 'KT.SALES']
            ])->fetchObject();
            if ($ktSalesGroup) {
                $projectId = $ktSalesGroup->getId();
            }
            if ($projectId && $clientIds) {
                $element = new \CIBlockElement();
                $iblockObject = ElementCallMeetingWithClientTable::getEntity()->getIblock();
                $arFileds = [
                    'IBLOCK_ID' => $iblockObject->getId(),
                    'NAME' => $fields['SUBJECT'],
                    'CREATED_BY' => $fields['RESPONSIBLE_ID'],
                    'PROPERTY_VALUES' => [
                        'PROJECT' => $projectId,
                        'TIP_SOBYTIYA' => $fields['PROVIDER_TYPE_ID'] == 'CALL' ? 773 : 774,
                        'UCHASTNIKI_ZVONKA' => [$fields['RESPONSIBLE_ID']],
                        'OTVETSTVENNYY_ZA_PROTOKOL' => $fields['RESPONSIBLE_ID'],
                        'KLIENTY' => $clientIds,
                        'DATA_I_VREMYA_ZVONKA' => $fields['START_TIME'],
                        'DURATION_MIN' => (strtotime($fields['END_TIME']) - strtotime($fields['START_TIME'])) / 60,
                        'METOD_SVYAZI_MESTO_VSTRECHI' => !empty($fields['LOCATION']) ? $fields['LOCATION'] : '',
                        'AUTO_CREATED' => 1,
                    ],
                ];

                $docId = $element->Add($arFileds);
                \CBPDocument::AutoStartWorkflows(
                    ['lists', 'BizprocDocument', 'iblock_' . $iblockObject->getId()],
                    \CBPDocumentEventType::Create,
                    ['lists', 'BizprocDocument', $docId],
                    [],
                    $arErrors
                );
            }
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