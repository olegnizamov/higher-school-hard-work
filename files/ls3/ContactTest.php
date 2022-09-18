<?php

namespace Kt\IntegrationTests\Main\Crm\Lead;

use Bitrix\Main\Config\Option;
use Kt\Crm\Contact\Contact;
use Kt\Tests\BitrixTestCase;

/**
 * @coversDefaultClass \Kt\Crm\Contact\Contact
 *
 * @internal
 */
class ContactTest extends BitrixTestCase
{
    /**
     * @test
     * @covers ::requireDetailUrl
     */
    public function requireDetailUrl()
    {
        $contact = new Contact(['ID' => self::getFaker()->randomNumber()]);
        $path = Option::get('crm', 'path_to_contact_details');
        $path = str_replace('#contact_id#', $contact->requireId(), $path);
        $this->assertEquals($path, $contact->requireDetailUrl());
    }
}
