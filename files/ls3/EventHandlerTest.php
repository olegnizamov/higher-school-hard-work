<?php

namespace Kt\Integration\Crm;

use Bitrix\Crm\ContactTable;
use Kt\Crm\EventHandler;
use Kt\Tests\BitrixTestCase;

/**
 * Class EventHandlerTest.
 * @internal
 * @coversDefaultClass \Kt\Crm\EventHandler
 */
final class EventHandlerTest extends BitrixTestCase
{
    /**
     * Тест метода onBeforeCrmDealUpdate.
     *
     * @covers ::onBeforeCrmDealUpdate
     */
    public function testOnBeforeCrmDealUpdate(): void
    {
        $arFields = [];
        $arFields['CONTACT_IDS'] = [];
        EventHandler::onBeforeCrmDealUpdate($arFields);
        $this->assertEquals([], $arFields['CONTACT_IDS']);

        $arFields['CONTACT_IDS'] = [];
        $lastContactId = ContactTable::getList([
            'order' => ['ID' => 'desc'],
            'limit' => 1,
        ])->fetchObject()->getId();
        $notRealContactsIds = [
            $lastContactId + 1,
            $lastContactId + 2,
            $lastContactId + 3,
            $lastContactId + 4,
            $lastContactId + 5,
        ];
        $arFields['CONTACT_IDS'] = $notRealContactsIds;
        EventHandler::onBeforeCrmDealUpdate($arFields);
        $this->assertEquals([], $arFields['CONTACT_IDS']);
        $realContactsIds = ContactTable::getList([
            'limit' => 10,
        ])->fetchCollection()->getIdList();
        $arFields['CONTACT_IDS'] = array_merge($realContactsIds, $notRealContactsIds);
        EventHandler::onBeforeCrmDealUpdate($arFields);
        $this->assertSame($realContactsIds, $arFields['CONTACT_IDS']);
    }

    /**
     * Тест метода onBeforeCrmCompanyUpdate.
     *
     * @covers ::onBeforeCrmCompanyUpdate
     */
    public function testOnBeforeCrmCompanyUpdate(): void
    {
        $arFields = [];
        $arFields['CONTACT_ID'] = [];
        EventHandler::onBeforeCrmCompanyUpdate($arFields);
        $this->assertEquals([], $arFields['CONTACT_ID']);

        $arFields['CONTACT_ID'] = [];
        $lastContactId = ContactTable::getList([
            'order' => ['ID' => 'desc'],
            'limit' => 1,
        ])->fetchObject()->getId();
        $notRealContactsIds = [
            $lastContactId + 1,
            $lastContactId + 2,
            $lastContactId + 3,
            $lastContactId + 4,
            $lastContactId + 5,
        ];
        $arFields['CONTACT_ID'] = $notRealContactsIds;
        EventHandler::onBeforeCrmCompanyUpdate($arFields);
        $this->assertEquals([], $arFields['CONTACT_ID']);
        $realContactsIds = ContactTable::getList([
            'limit' => 10,
        ])->fetchCollection()->getIdList();
        $arFields['CONTACT_ID'] = array_merge($realContactsIds, $notRealContactsIds);
        EventHandler::onBeforeCrmCompanyUpdate($arFields);
        $this->assertSame($realContactsIds, $arFields['CONTACT_ID']);
    }
}
