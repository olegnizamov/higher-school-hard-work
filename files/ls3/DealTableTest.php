<?php

namespace Kt\IntegrationTests\Main\Crm\Deal;

use Bitrix\Currency\Integration\IblockMoneyProperty;
use CCrmCompany;
use CCrmDeal;
use CSocNetGroup;
use Kt\Crm\Deal\DealTable;
use Kt\Iblock\Lists\Agreements\ElementAgreementsTable;
use Kt\Socialnetwork\WorkgroupTable;
use Kt\Tests\BitrixTestCase;

/**
 * @coversDefaultClass \Kt\Crm\Deal\DealTable
 *
 * @internal
 */
class DealTableTest extends BitrixTestCase
{
    /**
     * @test
     * @covers ::getDealsByAgreement
     */
    public function getDealsByAgreement()
    {
        // Создадим проект
        $workgroupId = BitrixTestCase::createWorkgroup();
        $workgroup = WorkgroupTable::query()
            ->addSelect('NAME')
            ->where('ID', $workgroupId)
            ->fetchObject()
        ;

        // Создадим сделки
        $dealId1 = BitrixTestCase::createDeal();
        $dealId2 = BitrixTestCase::createDeal();

        // Создадим компанию
        $companyId = BitrixTestCase::createCompany();

        // Создадим договор
        // Привяжем к нему проект, сделки, компанию
        $agreementId = BitrixTestCase::createAgreement([
            'PROPERTY_VALUES' => [
                'PRODUCT_PRICE_DEFAULT' => self::getFaker()->randomNumber(2)
                    . IblockMoneyProperty::SEPARATOR . \CCrmCurrency::GetDefaultCurrencyID(),
                'COUNTERPARTY' => [(int) $companyId],
                'AGREEMENT_DEALS' => [(int) $dealId1, (int) $dealId2],
                'WORKGROUP_ID' => $workgroup->getId(),
            ],
        ]);

        // Достанем созданный договор с полем связи DEALS_PROPERTY
        $agreement = ElementAgreementsTable::query()
            ->addSelect('DEALS_PROPERTY')
            ->where('ID', $agreementId)
            ->fetchObject()
        ;

        // Проверим тестируемый метод
        $actualDeals = DealTable::getDealsByAgreement($agreement);
        $this->assertEquals(2, $actualDeals->count());
        $this->assertEquals($actualDeals->getIdList(), [(int) $dealId1, (int) $dealId2]);

        // Удалим сделки
        $deal = new CCrmDeal(false);
        $deal->Delete($dealId1);
        $deal->Delete($dealId2);

        // Удалим компанию
        $company = new CCrmCompany(false);
        $company->Delete($companyId);

        // Удалим Проект
        CSocNetGroup::Delete($workgroupId);
    }
}
