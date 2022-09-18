<?php

namespace Kt\UnitTests\Crm\Deal;

use CCrmCurrency;
use Kt\Crm\Deal\Deal;
use Kt\Crm\Deal\DealCollection;
use Kt\Tests\BitrixTestCase;

/**
 * @covers \Kt\Crm\Deal\DealCollection
 *
 * @internal
 */
class DealCollectionTest extends BitrixTestCase
{
    /**
     * @test
     * @covers \Kt\Crm\Deal\DealCollection::getSumOpportunity
     */
    public function getSumOpportunity()
    {
        $faker = self::getFaker();
        $deal1Cost = $faker->randomNumber(2);
        $deal2Cost = $faker->randomNumber(2);

        $deals = new DealCollection();
        $deals->add(new Deal([
            'OPPORTUNITY' => $deal1Cost,
            'CURRENCY_ID' => CCrmCurrency::GetDefaultCurrencyID(),
        ]));
        $deals->add(new Deal([
            'OPPORTUNITY' => $deal2Cost,
            'CURRENCY_ID' => CCrmCurrency::GetDefaultCurrencyID(),
        ]));

        $actualSum = $deals->getSumOpportunity();
        $this->assertEquals($deal1Cost + $deal2Cost, $actualSum);
    }
}
