<?php

namespace Kt\Crm\Handlers;

use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;
use Bitrix\Crm\Timeline\Entity\TimelineTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use CCrmOwnerType;
use Kt\Crm\Activity\ActivityBindingTable;
use Kt\Crm\Deal\Deal;
use Kt\Crm\Deal\DealTable;

/**
 * Класс, содержащий общие обработчики событий активити для всех типов сущностей (лиды и контакты).
 */
class DealsHandler
{
    /**
     * Обработчик события добавления активити.
     * Если активити добавлено у неактивной сделки,
     * то активити удаляется у данной сделки.
     *
     * @param int   $id     ID активити
     * @param array $fields Поля
     *
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     *
     * @return bool
     *
     * @see \Kt\IntegrationTests\Main\Crm\Handlers\DealsHandlerTest::testOnActivityAdd test
     */
    public static function onActivityAdd(int $id, array &$fields)
    {
        if (!in_array(CCrmOwnerType::Deal, array_column($fields['BINDINGS'], 'OWNER_TYPE_ID'))
            || !isset($fields['SETTINGS']['EMAIL_META']['from'])) {
            return true;
        }

        /** Проверяем что сделка является неактивной */
        $dealId = array_column($fields['BINDINGS'], 'OWNER_ID', 'OWNER_TYPE_ID')[CCrmOwnerType::Deal];
        $dealObj = DealTable::getByPrimary($dealId)->fetchObject();

        if (Deal::isActive($dealObj)) {
            return true;
        }

        /** Получаем id активити в таблице b_crm_timeline и удаляем данную запись */
        $timelineObj = TimelineTable::query()
            ->where('ASSOCIATED_ENTITY_ID', $id)
            ->where('ASSOCIATED_ENTITY_TYPE_ID', CCrmOwnerType::Activity)
            ->fetchObject()
        ;

        if (null !== $timelineObj) {
            $timelineId = $timelineObj->getId();

            $timelineBindingObj = TimelineBindingTable::query()
                ->where('OWNER_ID', $timelineId)
                ->where('ENTITY_ID', $dealId)
                ->where('ENTITY_TYPE_ID', CCrmOwnerType::Deal)
                ->fetchObject()
            ;

            if (null !== $timelineBindingObj) {
                $timelineBindingObj->delete();
            }

            /**  Удаляем запись из таблице b_crm_act_bind */
            $activityBindingObj = ActivityBindingTable::query()
                ->where('ACTIVITY_ID', $id)
                ->where('OWNER_ID', $dealId)
                ->where('OWNER_TYPE_ID', CCrmOwnerType::Deal)
                ->fetchObject()
            ;

            if (null !== $activityBindingObj) {
                $activityBindingObj->delete();
            }
        }

        return true;
    }
}
