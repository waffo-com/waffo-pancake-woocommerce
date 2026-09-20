<?php
namespace WaffoPancake\Order;

/**
 * 把Waffo侧的订单结果同步到WooCommerce订单。
 *
 * webhook（Waffo_Webhook_Controller 的 on_event）和 WP-Cron 兜底轮询（Waffo_Order_Reconciler）
 * 两条链路都收敛到这里，保证"付款成功→WC订单变为已付款并记录PAY_id"只有一种实现，
 * 且天然幂等：同一笔付款不论先从哪条链路到达、到达几次，结果一致。
 *
 * 订单对象不做类型约束（鸭子类型），只依赖 WC_Order 的以下方法：
 * has_status / get_meta / update_meta_data / add_order_note / payment_complete / update_status / save。
 * 这样纯逻辑可以在没有WooCommerce的PHPUnit环境里用假订单对象覆盖。
 */
class Waffo_Order_Sync
{
    public const META_PAYMENT_ID = '_waffo_payment_id';

    /** WC里视为"已付款"的状态，再次收到付款成功不应重复触发payment_complete */
    private const PAID_STATUSES = ['processing', 'completed', 'refunded'];

    /** WC里视为"等待付款"的状态，只有这些状态允许被同步为已取消 */
    private const AWAITING_PAYMENT_STATUSES = ['pending', 'on-hold'];

    /** @var callable(string): (object|null) */
    private $find_order;
    /** @var callable(string): void */
    private $log;

    /**
     * @param callable $find_order 接收WC订单ID字符串，返回WC_Order或null（生产环境传 wc_get_order 的包装）
     * @param callable|null $log    接收一行日志文本；默认error_log
     */
    public function __construct(callable $find_order, ?callable $log = null)
    {
        $this->find_order = $find_order;
        $this->log = $log ?? static function (string $message): void {
            error_log('[waffo_pancake] ' . $message);
        };
    }

    /**
     * 标记订单已付款。返回true表示本次真正把订单推进到了已付款；false表示订单不存在或已是已付款（幂等命中）。
     */
    public function mark_paid(string $wc_order_id, ?string $payment_id, string $source): bool
    {
        $order = $this->load($wc_order_id);
        if ($order === null) {
            return false;
        }

        if ($order->has_status(self::PAID_STATUSES)) {
            // 幂等：已付款订单只补齐缺失的payment id（退款依赖它），不重复走payment_complete
            if ($payment_id !== null && $payment_id !== '' && $order->get_meta(self::META_PAYMENT_ID) === '') {
                $order->update_meta_data(self::META_PAYMENT_ID, $payment_id);
                $order->save();
            }
            return false;
        }

        if ($payment_id !== null && $payment_id !== '') {
            $order->update_meta_data(self::META_PAYMENT_ID, $payment_id);
        }
        $order->add_order_note(sprintf('Waffo Pancake payment confirmed (%s). Payment ID: %s', $source, $payment_id ?? 'n/a'));
        // payment_complete() 会按商品类型决定 processing/completed，并触发WC标准的付款完成钩子
        $order->payment_complete($payment_id ?? '');
        $order->save();

        return true;
    }

    /**
     * 标记订单已取消（Waffo侧checkout过期或买家取消）。只处理仍在等待付款的订单，避免误伤已付款订单。
     */
    public function mark_canceled(string $wc_order_id, string $reason): bool
    {
        $order = $this->load($wc_order_id);
        if ($order === null || !$order->has_status(self::AWAITING_PAYMENT_STATUSES)) {
            return false;
        }

        $order->update_status('cancelled', 'Waffo Pancake: ' . $reason);
        $order->save();

        return true;
    }

    /**
     * webhook事件分发入口（已验签、已去重的事件）。
     * 不是本插件创建的订单（缺 orderMerchantExternalId）直接忽略。
     */
    public function apply_webhook_event(array $event): void
    {
        $type = (string) ($event['eventType'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $wc_order_id = (string) ($data['orderMerchantExternalId'] ?? '');

        if ($wc_order_id === '') {
            return;
        }

        if ($type === 'order.completed') {
            $this->mark_paid($wc_order_id, $data['paymentId'] ?? null, 'webhook ' . $type);
            return;
        }

        if (str_starts_with($type, 'refund.')) {
            $order = $this->load($wc_order_id);
            if ($order !== null) {
                // 退款状态只留痕：WC侧的退款记录在 process_refund() 提交工单时已创建；
                // refund.failed 需要商户人工介入，所以把状态写进备注让后台能看到
                $order->add_order_note(sprintf(
                    'Waffo Pancake refund %s: %s %s (event %s).',
                    (string) ($data['refundStatus'] ?? 'updated'),
                    (string) ($data['amount'] ?? '?'),
                    (string) ($data['currency'] ?? ''),
                    (string) ($event['eventId'] ?? '')
                ));
                $order->save();
            }
            return;
        }

        if (str_starts_with($type, 'subscription.')) {
            // 订阅生命周期尚未接入WC Subscriptions（见设计文档遗留项），先留痕不改状态
            $order = $this->load($wc_order_id);
            if ($order !== null) {
                $order->add_order_note(sprintf('Waffo Pancake subscription event received: %s', $type));
                $order->save();
            }
        }
    }

    private function load(string $wc_order_id): ?object
    {
        $order = ($this->find_order)($wc_order_id);
        if (!is_object($order)) {
            ($this->log)(sprintf('Order sync skipped: WooCommerce order #%s not found', $wc_order_id));
            return null;
        }
        return $order;
    }
}
