<?php
/**
 * Plugin Name:          Waffo Pancake for WooCommerce
 * Plugin URI:           https://github.com/waffo-com/waffo-pancake-woocommerce
 * Description:          Accept payments via Waffo Pancake hosted checkout. Waffo Pancake acts as Merchant of Record for software and digital products.
 * Version:              0.1.1
 * Author:               Waffo
 * Author URI:           https://waffo.ai
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          waffo-pancake-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WAFFO_PANCAKE_WC_VERSION', '0.1.1');
define('WAFFO_PANCAKE_WC_PLUGIN_FILE', __FILE__);
define('WAFFO_PANCAKE_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once WAFFO_PANCAKE_WC_PLUGIN_DIR . 'vendor/autoload.php';

// Cron兜底任务的注册/注销跟随插件启停；停用后不再留下孤儿定时任务
register_activation_hook(__FILE__, [\WaffoPancake\Cron\Waffo_Reconcile_Scheduler::class, 'activate']);
register_deactivation_hook(__FILE__, [\WaffoPancake\Cron\Waffo_Reconcile_Scheduler::class, 'deactivate']);

// 声明兼容 HPOS（高性能订单存储）和区块结账页；两者都是新 WooCommerce 店铺的默认设置
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// 区块结账页的支付方式注册
add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
    require_once WAFFO_PANCAKE_WC_PLUGIN_DIR . 'includes/Gateway/class-waffo-pancake-blocks.php';
    $registry->register(new \WaffoPancake\Gateway\Waffo_Pancake_Blocks());
});

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

    // 给本网关的WC退款记录打标识，refund.failed 时据此撤销（见 Waffo_Order_Sync::roll_back_refund）
    add_action('woocommerce_create_refund', [\WaffoPancake\Gateway\WC_Gateway_Waffo_Pancake::class, 'tag_refund'], 10, 2);

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
