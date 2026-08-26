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
});
