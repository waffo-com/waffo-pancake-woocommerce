<?php
namespace WaffoPancake\Order;

class Order_Status_Mapper
{
    // 映射表来自设计文档第6节；订阅侧状态名是WC Subscriptions插件的标准状态slug
    private const EVENT_TO_STATUS = [
        'order.completed'                    => 'processing',
        'subscription.activated'             => 'active',
        'subscription.payment_succeeded'     => 'active',
        'subscription.canceling'             => 'pending-cancel',
        'subscription.uncanceled'            => 'active',
        'subscription.canceled'              => 'cancelled',
        'subscription.past_due'              => 'on-hold',
    ];

    public static function for_event(string $event_type): ?string
    {
        return self::EVENT_TO_STATUS[$event_type] ?? null;
    }
}
