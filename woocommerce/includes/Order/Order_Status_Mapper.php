<?php
namespace WaffoPancake\Order;

class Order_Status_Mapper
{
    // order.completed => processing 摘自设计文档第6节；其余5个订阅事件映射
    // 参照WC Subscriptions插件的标准状态slug（active/pending-cancel/cancelled/on-hold）整理，
    // 设计文档第6节并未定义这些订阅状态映射，如需核对请勿去文档里找
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
