<?php
namespace WaffoPancake\Cron;

use WaffoPancake\Order\Waffo_Order_Sync;
use WaffoPancake\Waffo_Settings;

/**
 * WP-Cron胶水层：注册定时任务，筛选出需要对账的WC订单，交给 Waffo_Order_Reconciler。
 *
 * 依赖 wc_get_orders / wp_schedule_event 等WordPress运行时函数，无法在纯PHPUnit环境下
 * 单元测试，仅做 php -l 语法检查；可测的判断逻辑都在 Waffo_Order_Reconciler / Waffo_Order_Sync。
 *
 * 轮询窗口：
 *   - 下单后至少过 MIN_AGE 秒才查（给webhook留出正常送达的时间，避免与webhook竞争写订单）
 *   - 只查 MAX_AGE 内的订单（Waffo checkout session本身会过期；更老的订单交给商户人工处理）
 *   - 每轮最多 BATCH_SIZE 单，避免单次Cron跑太久
 */
class Waffo_Reconcile_Scheduler
{
    public const HOOK = 'waffo_pancake_reconcile_orders';
    public const INTERVAL = 'waffo_pancake_every_15_minutes';
    public const INTERVAL_SECONDS = 15 * MINUTE_IN_SECONDS;

    private const MIN_AGE_SECONDS = 10 * MINUTE_IN_SECONDS;
    private const MAX_AGE_SECONDS = 3 * DAY_IN_SECONDS;
    private const BATCH_SIZE = 50;

    public static function register(): void
    {
        add_filter('cron_schedules', [self::class, 'add_interval']);
        add_action(self::HOOK, [self::class, 'run']);
        // 兜底：插件升级/迁站后activation hook可能没跑，init时确认任务在队列里
        add_action('init', [self::class, 'ensure_scheduled']);
    }

    public static function add_interval(array $schedules): array
    {
        $schedules[self::INTERVAL] = [
            'interval' => self::INTERVAL_SECONDS,
            'display'  => 'Every 15 minutes (Waffo Pancake reconciliation)',
        ];
        return $schedules;
    }

    public static function ensure_scheduled(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + self::INTERVAL_SECONDS, self::INTERVAL, self::HOOK);
        }
    }

    public static function activate(): void
    {
        self::ensure_scheduled();
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public static function run(): void
    {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        if (!Waffo_Settings::is_api_configured()) {
            return;
        }

        $store_id = Waffo_Settings::store_id();
        if ($store_id === '') {
            Waffo_Settings::log('warning', 'Reconcile skipped: Store ID is not configured in the Waffo Pancake gateway settings.');
            return;
        }

        $order_ids = self::find_orders_awaiting_payment();
        if ($order_ids === []) {
            return;
        }

        $sync = new Waffo_Order_Sync(
            static fn (string $id) => wc_get_order((int) $id) ?: null,
            static fn (string $message) => Waffo_Settings::log('info', $message)
        );

        $reconciler = new Waffo_Order_Reconciler(
            Waffo_Settings::make_api_client(),
            $store_id,
            $sync,
            static fn (string $message) => Waffo_Settings::log('warning', $message)
        );

        $results = $reconciler->reconcile_many($order_ids);

        Waffo_Settings::log('info', sprintf(
            'Reconcile run: checked %d order(s), completed=%d canceled=%d pending=%d unresolved=%d',
            count($results),
            count(array_keys($results, 'completed', true)),
            count(array_keys($results, 'canceled', true)),
            count(array_keys($results, 'pending', true)),
            count(array_keys($results, null, true))
        ));
    }

    /**
     * @return string[] WC订单ID列表
     */
    private static function find_orders_awaiting_payment(): array
    {
        $now = time();

        $orders = wc_get_orders([
            'status'         => ['on-hold', 'pending'],
            'payment_method' => Waffo_Settings::GATEWAY_ID,
            'limit'          => self::BATCH_SIZE,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'date_created'   => ($now - self::MAX_AGE_SECONDS) . '...' . ($now - self::MIN_AGE_SECONDS),
            'return'         => 'ids',
            // 只对账真正发起过Waffo结账的订单（process_payment成功创建session后才会写这个meta）
            'meta_query'     => [
                [
                    'key'     => '_waffo_checkout_session_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        return array_map('strval', is_array($orders) ? $orders : []);
    }
}
