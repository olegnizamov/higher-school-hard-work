<?php

namespace Crm\Company;

use Bitrix\Main\SiteTable;
use JsonSchema\Uri\Retrievers\Curl;
use Kt\Crm\Company\Company;
use Kt\Crm\Company\CompanyTable;
use Kt\Tests\BitrixTestCase;
use Symfony\Component\Serializer\Tests\Normalizer\Features\TypeEnforcementTestTrait;
use Bitrix\Main\Context;

/**
 * @coversDefaultClass \Kt\Crm\Company\Company;
 */
class CompanyTest extends BitrixTestCase
{
    /**
     * @covers ::requireDetailUrl
     */
    public function testRequireDetailUrl()
    {
        /** @var Company $company Компания */
        $company = CompanyTable::getList(['limit' => 1])->fetchObject();
        /** @var string $companyUrl Url компании в CRM */
        $companyUrl = $company->requireDetailUrl();
        $this->assertNotEmpty($companyUrl);
    }
}
