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
                'type'        => 'password',
                'description' => 'RSA private key generated in Waffo Dashboard. Stored encrypted at rest is recommended; never logged or displayed in plaintext after initial entry.',
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
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice('Order not found.', 'error');
            return ['result' => 'fail'];
        }

        try {
            $client = $this->build_api_client();

            $session = $client->create_checkout_session([
                'productId'               => $this->resolve_waffo_product_id($order),
                'currency'                => $order->get_currency(),
                'orderMerchantExternalId' => (string) $order_id,
                'buyerEmail'              => $order->get_billing_email(),
                'successUrl'              => $this->get_return_url($order),
            ]);

            $order->update_status('on-hold', 'Awaiting Waffo Pancake payment confirmation.');
            $order->update_meta_data('_waffo_checkout_session_id', $session['sessionId']);
            $order->save();

            return [
                'result'   => 'success',
                'redirect' => $session['checkoutUrl'],
            ];
        } catch (\WaffoPancake\Api\Waffo_Api_Exception $e) {
            $this->log_payment_error($order_id, sprintf(
                '%s (status=%d, error_code=%s)',
                $e->getMessage(),
                $e->getStatusCode(),
                $e->getErrorCode() ?? 'n/a'
            ));
            wc_add_notice('Payment could not be started. Please try again or contact the store.', 'error');
            return ['result' => 'fail'];
        } catch (\Throwable $e) {
            // 纵深防御：捕获任何未预期的本地/运行时错误（例如未来新增的校验类型），
            // 避免绕过上面的Waffo_Api_Exception catch导致未捕获异常直达买家浏览器。
            $this->log_payment_error($order_id, $e->getMessage());
            wc_add_notice('Payment could not be started. Please try again or contact the store.', 'error');
            return ['result' => 'fail'];
        }
    }

    private function log_payment_error(int $order_id, string $detail): void
    {
        // 详细错误信息只写日志供商户/开发者排查，绝不通过wc_add_notice展示给买家，
        // 避免泄漏上游API的原始错误文案或未来可能包含的诊断细节。
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                sprintf('[waffo_pancake] process_payment failed for order #%d: %s', $order_id, $detail),
                ['source' => 'waffo_pancake']
            );
            return;
        }

        error_log(sprintf('[waffo_pancake] process_payment failed for order #%d: %s', $order_id, $detail));
    }

    private function build_api_client(): \WaffoPancake\Api\Waffo_Api_Client
    {
        $environment = $this->get_option('environment', 'test');
        $base_url = $environment === 'prod' ? 'https://api.waffo.ai' : 'https://api.test.waffo.ai';

        $signer = new \WaffoPancake\Signing\Waffo_Signer($this->get_option('private_key', ''));

        return new \WaffoPancake\Api\Waffo_Api_Client($signer, $this->get_option('merchant_id', ''), $base_url);
    }

    private function resolve_waffo_product_id(\WC_Order $order): string
    {
        // 方案已定案（2026-08-28）：商品自定义字段_waffo_product_id，商户在商品编辑页手动填写
        // （见Task 6.5的商品编辑页UI实现）。参照Paddle/Lemon Squeezy等MoR类插件的业界惯例。
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $waffo_product_id = $product->get_meta('_waffo_product_id');
                if ($waffo_product_id) {
                    return $waffo_product_id;
                }
            }
        }

        throw new \WaffoPancake\Api\Waffo_Api_Exception('No Waffo product mapping found for this order. Configure "_waffo_product_id" on the product (variable/variation products are not yet supported).');
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        // TODO(Task 8+): 对接退款接口；具体交互模式依赖设计文档§10.4待确认的审核机制
        return new \WP_Error('not_implemented', 'Refund handling pending confirmation of Waffo refund review process (see design doc §10.4)');
    }
}
