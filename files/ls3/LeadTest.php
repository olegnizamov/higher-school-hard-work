<?php

namespace Kt\IntegrationTests\Main\Crm\Lead;

use Bitrix\Main\Config\Option;
use Kt\Crm\Lead\Lead;
use Kt\Tests\BitrixTestCase;

/**
 * @coversDefaultClass \Kt\Crm\Lead\Lead
 */
class LeadTest extends BitrixTestCase
{
    /**
     * @test
     * @covers ::requireDetailUrl
     */
    public function requireDetailUrl()
    {
        $lead = new Lead(['ID' => self::getFaker()->randomNumber()]);
        $path = Option::get('crm', 'path_to_lead_details');
        $path = str_replace('#lead_id#', $lead->requireId(), $path);
        $this->assertEquals($path, $lead->requireDetailUrl());
    }
}
