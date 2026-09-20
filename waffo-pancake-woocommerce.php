<?php
/**
 * Plugin Name: Waffo Pancake for WooCommerce
 * Description: Accept payments via Waffo Pancake hosted checkout.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WAFFO_PANCAKE_WC_PLUGIN_FILE', __FILE__);
define('WAFFO_PANCAKE_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once WAFFO_PANCAKE_WC_PLUGIN_DIR . 'vendor/autoload.php';

// Cron兜底任务的注册/注销跟随插件启停；停用后不再留下孤儿定时任务
register_activation_hook(__FILE__, [\WaffoPancake\Cron\Waffo_Reconcile_Scheduler::class, 'activate']);
register_deactivation_hook(__FILE__, [\WaffoPancake\Cron\Waffo_Reconcile_Scheduler::class, 'deactivate']);

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>Waffo Pancake for WooCommerce requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    require_once WAFFO_PANCAKE_WC_PLUGIN_DIR . 'includes/Gateway/class-wc-gateway-waffo-pancake.php';

    add_filter('woocommerce_payment_gateways', function (array $gateways): array {
        $gateways[] = \WaffoPancake\Gateway\WC_Gateway_Waffo_Pancake::class;
        return $gateways;
    });

    \WaffoPancake\Product\Waffo_Product_Fields::register();

    // ---- Webhook：验签 → 去重 → 同步WC订单 ----
    // 验签公钥按后台Environment选项选择（test/prod各一把平台固定公钥，商户无需配置）。
    // 事件里的 mode 字段与之不一致时验签会失败并返回401，这是预期行为：防止test事件打到prod站点。
    $verifier = new \WaffoPancake\Signing\Waffo_Webhook_Verifier(
        \WaffoPancake\Signing\Waffo_Webhook_Public_Keys::for_mode(\WaffoPancake\Waffo_Settings::environment())
    );
    $dedup = new \WaffoPancake\Dedup\Waffo_Event_Deduplicator(new \WaffoPancake\Dedup\WP_Transient_Event_Store());
    $sync = new \WaffoPancake\Order\Waffo_Order_Sync(
        static fn (string $id) => wc_get_order((int) $id) ?: null,
        static fn (string $message) => \WaffoPancake\Waffo_Settings::log('info', $message)
    );

    $webhook_controller = new \WaffoPancake\Webhook\Waffo_Webhook_Controller($verifier, $dedup, [$sync, 'apply_webhook_event']);
    $webhook_controller->register_routes();

    // ---- WP-Cron兜底轮询：webhook丢失时按 orderMerchantExternalId 反查Waffo订单状态 ----
    \WaffoPancake\Cron\Waffo_Reconcile_Scheduler::register();
});
