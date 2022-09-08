### Снижаем цикломатическую сложность

1. Изучите материал из СильныхИдей "Снижение цикломатической сложности (ЦС) кода как метод проектирования".
2. Выберите какую-нибудь свою или чужую функцию/метод (не менее 30 инструкций) и понизьте её ЦС в два раза.
3. В решении приведите исходный и результирующий версии кода, исходную и конечную ЦС и приёмы, которые вы использовали (например, избавление от else, полиморфизм, избавление от null, "табличная" логика...) -- не менее двух разных приёмов на каждую функцию.
4. Повторите пункты 2 и 3 ещё два раза с другим кодом.

## Решение

[Исходник](activitieseventhandler.php)
```
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
```

Рассуждение и приемы:

1) Разделить метод на подметоды, которые будет решать более мелкие задачи
2) Код можно вынести в отдельный метод и заменить тернарным оператором  ( ?: в си-подобных языках)

   ```
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
    ```
3) Сам метод onActivityAddLinkContactAndLead можно разделить на 3 метода
4) onActivityAddLinkContactAndLeadByHands - сразу проверку на условие выхода из метода
5) onActivityAddLinkContactAndLeadByHands - вынос инициализацию массива в отдельный метод
6) Удаляем else
```
            if ($reminder > 0) {
                $remindTime = date('d.m.Y H:i:s', strtotime($fields['START_TIME'] . " - $reminder second"));
            } else {
                $remindTime = $fields['START_TIME'];
            }
```
7) Блок кода выносим в начало и проверка добавляется в самом начале метода
```
 //HARD_WORK Проверка вынесена вверх - запрос кэшируется
        $ktSalesGroup = WorkgroupTable::getList([
            'filter' => ['UF_PROJECT_NAME' => 'KT.SALES'],
        ])->fetchObject();
        
```
[Результат](activitieseventhandler_result.php)
```
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
     * @param int $id ID активити
     * @param array $fields Поля
     *
     * @see Документация onActivityAdd https://dev.1c-bitrix.ru/api_help/crm/crm_events.php
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\ActivitiesHandlerTest::testOnActivityAddLinkContactAndLead Тест
     */
    public static function onActivityAddLinkContactAndLeadByHands(int $id, array &$fields): bool
    {
        /**
         * Если событие создаётся для лида.
         * Условие проверяе что активити создаётся именно руками а не автоматически по связи события календаря и crm
         * (Context::getCurrent()->getRequest()->get('ajax_action') == 'ACTIVITY_SAVE')
         */

        //HARD_WORK Проверка вынесена вверх - запрос кэшируется
        $ktSalesGroup = WorkgroupTable::getList([
            'filter' => ['UF_PROJECT_NAME' => 'KT.SALES'],
        ])->fetchObject();

        //HARD_WORK - сразу делаем проверку на условие выхода из метода
        if (
            (Context::getCurrent()->getRequest()->get('ajax_action') !== 'ACTIVITY_SAVE') ||
            ($fields['COMPLETED'] == 'Y') ||
            ($fields['PROVIDER_TYPE_ID'] !== 'CALL' && $fields['PROVIDER_TYPE_ID'] !== 'MEETING') ||
            ((int)$fields['TYPE_ID'] !== \CCrmActivityType::Call &&
                (int)$fields['TYPE_ID'] !== \CCrmActivityType::Meeting) ||
            (int)$fields['OWNER_TYPE_ID'] !== \CCrmOwnerType::Lead ||
            $ktSalesGroup == null
        ) {
            return true;
        }

        $eventFields = self::getEventFields($fields);
        /**
         * Значение в секундах еденицы измерения времени оповещения, при типе 1 и по умолчанию минуты.
         */
        //HARD_WORK Извлекаем метод и удаляем там switch-case
        $notifySize = self::getNotifySize($fields['NOTIFY_TYPE']);
        $reminder = $fields['NOTIFY_VALUE'] * $notifySize;
        /**
         * Расчитываем время оповещения.
         */
        //HARD_WORK Удаляем if-else
        $remindTime = $reminder > 0 ? date(
            'd.m.Y H:i:s',
            strtotime($fields['START_TIME'] . " - $reminder second")
        ) : $fields['START_TIME'];

        $eventId = \CCalendar::SaveEvent($eventFields);
        $arParams = [
            'eventId'      => $eventId,
            'userId'       => \CCalendar::GetCurUserId(true),
            'viewPath'     => '',
            'calendarType' => 'user',
            'ownerId'      => $fields['AUTHOR_ID'],
        ];

        //Добавляем оповещение
        \CCalendarReminder::AddAgent($remindTime, $arParams);
        $eventFields['arFields']['OWNER_ID'] = $ktSalesGroup->getId();
        $eventFields['arFields']['CAL_TYPE'] = 'group';
        \CCalendar::SaveEvent($eventFields);

        return true;
    }

    /**
     * @param array $fields
     * @return array
     */
    public static function getEventFields(array $fields)
    {
        $arFields = [
            'NAME'            => $fields['SUBJECT'],
            'OWNER_ID'        => $fields['RESPONSIBLE_ID'],
            'CAL_TYPE'        => 'user',
            'DESCRIPTION'     => $fields['DESCRIPTION'],
            'MEETING_HOST'    => $fields['RESPONSIBLE_ID'],
            'LOCATION'        => $fields['LOCATION'],
            'IS_MEETING'      => true,
            'RRULE'           => false,
            'ATTENDEES_CODES' => ['U_' . $fields['RESPONSIBLE_ID']],
            'DATE_FROM'       => $fields['START_TIME'],
            'DATE_TO'         => $fields['END_TIME'],
        ];
        $eventFields = [
            'arFields'          => $arFields,
            'UF'                => [
                'UF_CRM_CAL_EVENT' => ['L' . $fields['OWNER_ID']],
            ],
            'autoDetectSection' => true,
            'autoCreateSection' => true,
        ];
        return $eventFields;
    }


    public
    static function onActivityAddLinkContactAndLead(
        int $id,
        array &$fields
    ): bool {
        //HARD_WORK Проверка вынесена вверх - запрос кэшируется
        $ktSalesGroup = WorkgroupTable::getList([
            'filter' => ['UF_PROJECT_NAME' => 'KT.SALES'],
        ])->fetchObject();

        //HARD_WORK - сразу делаем проверку на условие выхода из метода
        if (
            (Context::getCurrent()->getRequest()->get('ajax_action') !== 'ACTIVITY_DONE') ||
            ($fields['COMPLETED'] == 'Y') ||
            ($fields['PROVIDER_TYPE_ID'] !== 'CALL' && $fields['PROVIDER_TYPE_ID'] !== 'MEETING') ||
            ((int)$fields['TYPE_ID'] !== \CCrmActivityType::Call &&
                (int)$fields['TYPE_ID'] !== \CCrmActivityType::Meeting) ||
            (int)$fields['OWNER_TYPE_ID'] !== \CCrmOwnerType::Lead ||
            $ktSalesGroup == null
        ) {
            return true;
        }

        $contactRefCollection = ContactCompanyTable::getList([
            'filter' => ['COMPANY_ID' => $fields['OWNER_ID']],
        ])->fetchCollection();
        $clientIds = $contactRefCollection->getContactIdList();
        $projectId = $ktSalesGroup->getId();

        if ($projectId == null || $clientIds == null) {
            return true;
        }

        $element = new \CIBlockElement();
        $iblockObject = ElementCallMeetingWithClientTable::getEntity()->getIblock();
        $arFileds = self::getFields($iblockObject, $fields, $projectId, $clientIds);
        $docId = $element->Add($arFileds);
        \CBPDocument::AutoStartWorkflows(
            ['lists', 'BizprocDocument', 'iblock_' . $iblockObject->getId()],
            \CBPDocumentEventType::Create,
            ['lists', 'BizprocDocument', $docId],
            [],
            $arErrors
        );
        return true;
    }

    /**
     * @param $iblockObject
     * @param array $fields
     * @param $projectId
     * @param $clientIds
     * @return array
     */
    public static function getFields($iblockObject, array $fields, $projectId, $clientIds): array
    {
        return [
            'IBLOCK_ID'       => $iblockObject->getId(),
            'NAME'            => $fields['SUBJECT'],
            'CREATED_BY'      => $fields['RESPONSIBLE_ID'],
            'PROPERTY_VALUES' => [
                'PROJECT'                     => $projectId,
                'TIP_SOBYTIYA'                => $fields['PROVIDER_TYPE_ID'] == 'CALL' ? 773 : 774,
                'UCHASTNIKI_ZVONKA'           => [$fields['RESPONSIBLE_ID']],
                'OTVETSTVENNYY_ZA_PROTOKOL'   => $fields['RESPONSIBLE_ID'],
                'KLIENTY'                     => $clientIds,
                'DATA_I_VREMYA_ZVONKA'        => $fields['START_TIME'],
                'DURATION_MIN'                => (strtotime($fields['END_TIME']) - strtotime(
                            $fields['START_TIME']
                        )) / 60,
                'METOD_SVYAZI_MESTO_VSTRECHI' => !empty($fields['LOCATION']) ? $fields['LOCATION'] : '',
                'AUTO_CREATED'                => 1,
            ],
        ];
    }

    /**
     * Получить email'ы распарсив строку.
     *
     * @param string $emails Список email'ов вида < example1@example.com >
     */
    private
    static function getEmailsByParsing(
        string $emails
    ): array {
        $result = [];
        preg_match('/<(.+)>/', $emails, $result);
        array_shift($result);

        return $result;
    }

    /**
     * @param $NOTIFY_TYPE
     * @return int
     */
    public
    static function getNotifySize(
        $notifyType
    ) {
        return ($notifyType == 2 ? 3600 : ($notifyType == 3 ? 86400 : 60));
    }
}
```



## Решение
[Исходник](KtAgreement.php)
[Результат](KtAgreement_result.php)

Был проведен рефакторинг методов:
- checkProjectCodeField - вынесен в отдельный метод код и SWITCH удален и изменен на массив данных
- getAgreementNumString - удаление else
