<?php
namespace WaffoPancake\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Order\Order_Status_Mapper;

class Order_Status_Mapper_Test extends TestCase
{
    public function test_order_completed_maps_to_processing(): void
    {
        $this->assertSame('processing', Order_Status_Mapper::for_event('order.completed'));
    }

    public function test_subscription_activated_maps_to_active(): void
    {
        $this->assertSame('active', Order_Status_Mapper::for_event('subscription.activated'));
    }

    public function test_subscription_canceled_maps_to_cancelled(): void
    {
        $this->assertSame('cancelled', Order_Status_Mapper::for_event('subscription.canceled'));
    }

    public function test_unknown_event_returns_null(): void
    {
        $this->assertNull(Order_Status_Mapper::for_event('some.unknown.event'));
    }
}
