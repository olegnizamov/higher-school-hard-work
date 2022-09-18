<?php

namespace Kt\UnitTests\Crm\Activity;

use Kt\Crm\Activity\ActivityBinding;
use Kt\Crm\Activity\ActivityBindingCollection;
use Kt\Tests\BitrixTestCase;

/**
 * @covers \Kt\Crm\Activity\ActivityBindingCollection
 */
class ActivityBindingCollectionTest extends BitrixTestCase
{
    /**
     * @test
     * @covers \Kt\Crm\Activity\ActivityBindingCollection::filterByOwnerTypeId
     */
    public function filterByOwnerTypeId()
    {
        $collection = new ActivityBindingCollection();
        $collection->add(new ActivityBinding(['OWNER_TYPE_ID' => \CCrmOwnerType::Lead]));
        $collection->add(new ActivityBinding(['OWNER_TYPE_ID' => \CCrmOwnerType::Lead]));
        $collection->add(new ActivityBinding(['OWNER_TYPE_ID' => \CCrmOwnerType::Contact]));
        $collection->add(new ActivityBinding(['OWNER_TYPE_ID' => \CCrmOwnerType::Deal]));
        $collection->add(new ActivityBinding(['OWNER_TYPE_ID' => \CCrmOwnerType::Deal]));
        $collection->add(new ActivityBinding(['OWNER_TYPE_ID' => \CCrmOwnerType::Deal]));
        $this->assertEquals(2, $collection->filterByOwnerTypeId(\CCrmOwnerType::Lead)->count());
        $this->assertEquals(1, $collection->filterByOwnerTypeId(\CCrmOwnerType::Contact)->count());
        $this->assertEquals(3, $collection->filterByOwnerTypeId(\CCrmOwnerType::Deal)->count());
    }
    /**
     * @test
     * @covers \Kt\Crm\Activity\ActivityBindingCollection::filterByOwnerId
     */
    public function filterByOwnerId()
    {
        $collection = new ActivityBindingCollection();
        $collection->add(new ActivityBinding(['OWNER_ID' => 1]));
        $collection->add(new ActivityBinding(['OWNER_ID' => 1]));
        $collection->add(new ActivityBinding(['OWNER_ID' => 2]));
        $collection->add(new ActivityBinding(['OWNER_ID' => 3]));
        $collection->add(new ActivityBinding(['OWNER_ID' => 3]));
        $collection->add(new ActivityBinding(['OWNER_ID' => 3]));
        $this->assertEquals(2, $collection->filterByOwnerId(1)->count());
        $this->assertEquals(1, $collection->filterByOwnerId(2)->count());
        $this->assertEquals(3, $collection->filterByOwnerId(3)->count());
    }
}
