<?php
// 文件名沿用WordPress传统的class-*.php惯例（而非其余类的PSR-4风格），
// 因为WC_Payment_Gateway基类只在plugins_loaded之后才存在，此文件必须由
// 插件入口手动require_once、不能走Composer autoload，命名差异是有意为之。
namespace WaffoPancake\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Waffo_Pancake extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id                 = 'waffo_pancake';
        $this->method_title       = 'Waffo Pancake';
        $this->method_description = 'Accept payments via Waffo Pancake hosted checkout.';
        $this->has_fields         = false;
        $this->supports           = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', 'Waffo Pancake');
        $this->description = $this->get_option('description', 'Pay securely via Waffo Pancake.');
        $this->enabled      = $this->get_option('enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        // TODO(Task 8+): 注册webhook REST端点、Cron兜底任务
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Waffo Pancake',
                'default' => 'no',
            ],
            'title' => [
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'Payment method title shown to customers at checkout.',
                'default'     => 'Waffo Pancake',
            ],
            'environment' => [
                'title'   => 'Environment',
                'type'    => 'select',
                'options' => ['test' => 'Test', 'prod' => 'Production'],
                'default' => 'test',
            ],
            'merchant_id' => [
                'title'       => 'Merchant ID',
                'type'        => 'text',
                'description' => 'Your Waffo Merchant ID (MER_xxx), from Waffo Dashboard API key settings.',
            ],
            'private_key' => [
                'title'       => 'Private Key (PEM)',
                'type'        => 'textarea',
                'description' => 'RSA private key generated in Waffo Dashboard. Kept confidential.',
            ],
            'waffo_public_key' => [
                'title'       => 'Waffo Public Key (PEM)',
                'type'        => 'textarea',
                'description' => 'Used to verify webhook signatures. (待确认：官方公钥获取渠道，见设计文档第10节)',
            ],
            'debug' => [
                'title'   => 'Debug Log',
                'type'    => 'checkbox',
                'label'   => 'Enable logging (excludes private key and full signatures)',
                'default' => 'no',
            ],
        ];
    }

    public function process_payment($order_id): array
    {
        // TODO(Task 8+): 调用 create-checkout-session，写入 orderMerchantExternalId=$order_id，重定向到checkoutUrl
        throw new \Exception('Not yet implemented — depends on confirming merchant checkout-session API details (see design doc §10.1-2)');
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        // TODO(Task 8+): 对接退款接口；具体交互模式依赖设计文档§10.4待确认的审核机制
        return new \WP_Error('not_implemented', 'Refund handling pending confirmation of Waffo refund review process (see design doc §10.4)');
    }
}
