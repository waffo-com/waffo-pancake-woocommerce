<?php
// 与网关类同理：AbstractPaymentMethodType 只在 WooCommerce 加载后存在，
// 由插件入口在 woocommerce_blocks_payment_method_type_registration 回调里手动 require。
namespace WaffoPancake\Gateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 让网关出现在区块结账页（WooCommerce 8.3+ 新店默认的 Checkout block）。
 * 付款仍走服务端 process_payment()，区块侧只负责展示支付方式并跟随重定向。
 */
class Waffo_Pancake_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'waffo_pancake';

    public function initialize(): void
    {
        $settings = get_option('woocommerce_waffo_pancake_settings', []);
        $this->settings = is_array($settings) ? $settings : [];
    }

    public function is_active(): bool
    {
        return ($this->settings['enabled'] ?? 'no') === 'yes';
    }

    public function get_payment_method_script_handles(): array
    {
        wp_register_script(
            'waffo-pancake-blocks',
            plugins_url('assets/js/blocks.js', WAFFO_PANCAKE_WC_PLUGIN_FILE),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            WAFFO_PANCAKE_WC_VERSION,
            true
        );

        return ['waffo-pancake-blocks'];
    }

    public function get_payment_method_data(): array
    {
        return [
            'title'       => $this->settings['title'] ?? 'Waffo Pancake',
            'description' => $this->settings['description'] ?? 'Pay securely via Waffo Pancake.',
            'supports'    => ['products'],
        ];
    }
}
