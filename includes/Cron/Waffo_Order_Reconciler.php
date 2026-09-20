<?php
namespace WaffoPancake\Cron;

use WaffoPancake\Api\Waffo_Api_Client;
use WaffoPancake\Api\Waffo_Api_Exception;
use WaffoPancake\Order\Waffo_Order_Sync;

/**
 * WP-Cron兜底轮询的核心对账逻辑：给定一个WC订单号，去Waffo反查一次性订单的最终状态，
 * 并通过 Waffo_Order_Sync 推进WC订单。用于webhook丢失/被防火墙拦截/站点当时不可达的场景。
 *
 * 一次性订单只有三种状态（见官方文档 One-Time Order Lifecycle）：
 *   pending   — 中间态，买家被拒后可在同一订单上重试，留给下一轮
 *   completed — 终态，付款成功
 *   canceled  — 终态，checkout过期或买家取消
 * 没有 failed 状态。
 *
 * 本类只负责"查一个、判一个"；筛选哪些WC订单需要轮询、注册定时任务在 Waffo_Reconcile_Scheduler。
 */
class Waffo_Order_Reconciler
{
    private Waffo_Api_Client $client;
    private string $store_id;
    private Waffo_Order_Sync $sync;
    /** @var callable(string): void */
    private $log;

    public function __construct(Waffo_Api_Client $client, string $store_id, Waffo_Order_Sync $sync, ?callable $log = null)
    {
        $this->client = $client;
        $this->store_id = $store_id;
        $this->sync = $sync;
        $this->log = $log ?? static function (string $message): void {
            error_log('[waffo_pancake] ' . $message);
        };
    }

    /**
     * @return string|null Waffo侧订单状态；查询失败或未找到返回null
     */
    public function reconcile_one(string $wc_order_id): ?string
    {
        try {
            $order = $this->client->find_onetime_order_by_external_id($this->store_id, $wc_order_id);
        } catch (Waffo_Api_Exception $e) {
            // 单个订单查询失败不应中断整批轮询，留给下一次Cron周期重试
            ($this->log)(sprintf('Reconcile failed for WC order #%s: %s', $wc_order_id, $e->getMessage()));
            return null;
        }

        if ($order === null) {
            ($this->log)(sprintf('Reconcile: no Waffo order found for WC order #%s (store %s)', $wc_order_id, $this->store_id));
            return null;
        }

        $status = (string) ($order['status'] ?? '');

        if ($status === 'completed') {
            $this->sync->mark_paid($wc_order_id, $this->succeeded_payment_id($order), 'cron reconcile');
        } elseif ($status === 'canceled') {
            $this->sync->mark_canceled($wc_order_id, 'checkout expired or canceled (cron reconcile)');
        }

        return $status !== '' ? $status : null;
    }

    /**
     * @param string[] $wc_order_ids
     * @return array<string, string|null> 每个订单号对应的Waffo状态
     */
    public function reconcile_many(array $wc_order_ids): array
    {
        $results = [];
        foreach ($wc_order_ids as $id) {
            $results[(string) $id] = $this->reconcile_one((string) $id);
        }
        return $results;
    }

    private function succeeded_payment_id(array $order): ?string
    {
        foreach ($order['payments'] ?? [] as $payment) {
            if (($payment['status'] ?? '') === 'succeeded' && !empty($payment['id'])) {
                return (string) $payment['id'];
            }
        }
        return null;
    }
}
