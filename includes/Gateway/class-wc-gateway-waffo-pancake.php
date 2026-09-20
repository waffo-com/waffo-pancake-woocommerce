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

        // webhook REST端点与Cron兜底任务不在网关实例里注册（网关只在结账/设置页被实例化），
        // 统一在插件入口 waffo-pancake-woocommerce.php 的 plugins_loaded 里接线。
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
                'title'       => 'Environment',
                'type'        => 'select',
                'options'     => ['test' => 'Test', 'prod' => 'Production'],
                'default'     => 'test',
                'description' => 'Must match the environment your API key was created in (Waffo Dashboard → API & Development). This selects the webhook verification key; API requests always go to the same endpoint and the key itself determines test vs. production.',
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
            'store_id' => [
                'title'       => 'Store ID',
                'type'        => 'text',
                'description' => 'Your Waffo Store ID (STO_xxx), shown in Waffo Dashboard → store settings. Required for the background reconciliation job that recovers orders when a webhook is missed.',
            ],
            'waffo_tax_category' => [
                'title'       => 'Waffo Tax Category',
                'type'        => 'text',
                'description' => 'Tax category for pricing (e.g. "digital_goods", "saas"). See your Waffo Dashboard for valid values. Note: this gateway assumes your WooCommerce prices include tax (WooCommerce setting "Prices entered with tax" = Yes). If your store uses tax-exclusive pricing, contact support before enabling this gateway.',
                'default'     => 'digital_goods',
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
                'priceSnapshot'           => [
                    'amount'      => $this->build_price_snapshot_amount($order),
                    'taxIncluded' => true,
                    'taxCategory' => $this->get_option('waffo_tax_category', 'digital_goods'),
                ],
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
        // test/prod共用同一个API域名：API Key在Dashboard创建时就绑定了环境，
        // 服务端按验签成功的那把Key决定环境，不需要也不存在独立的test域名
        // （见官方文档 api-reference/authentication：API Key认证不需要X-Environment头）。
        // 后台的Environment选项只用于选择webhook验签公钥（见Waffo_Webhook_Public_Keys）。
        // 构造逻辑收敛在Waffo_Settings，webhook控制器与Cron调度器复用同一份。
        return \WaffoPancake\Waffo_Settings::make_api_client();
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

    /**
     * 把WC订单的服务端计算总额（$order->get_total()）转换成priceSnapshot需要的
     * 显示格式十进制字符串。金额必须来自订单总额，绝不能来自任何未经服务端验证的
     * 客户端原始输入，这是防止价格篡改的关键约束（见Task 6.6计划文档）。
     *
     * 转换逻辑本身在Waffo_Money::from_order_total()里实现并有纯PHPUnit测试覆盖
     * （不依赖WC_Order），这里只负责取值传递。
     */
    private function build_price_snapshot_amount(\WC_Order $order): string
    {
        return \WaffoPancake\Money\Waffo_Money::from_order_total($order->get_total(), $order->get_currency());
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('invalid_order', 'Order not found.');
        }

        $payment_id = $order->get_meta('_waffo_payment_id');
        if (!$payment_id) {
            return new \WP_Error('missing_payment_id', 'No Waffo payment ID recorded on this order; cannot request refund.');
        }

        // $amount为null表示WooCommerce调用方要求全额退款（标准process_refund()契约，
        // 例如后台"Refund manually"未指定金额时）。退回订单总额，与process_payment()当初
        // 发给Waffo的priceSnapshot.amount保持一致（两者都源自$order->get_total()，
        // 见build_price_snapshot_amount()的同一约束）。
        $refund_amount = $amount !== null ? (string) $amount : $order->get_total();

        try {
            $client = $this->build_api_client();

            $display_amount = \WaffoPancake\Money\Waffo_Money::from_order_total($refund_amount, $order->get_currency());

            $ticket = $client->create_refund_ticket($payment_id, $display_amount, $order->get_currency(), $reason ?: 'Refund requested via WooCommerce');

            $order->add_order_note(sprintf(
                'Waffo refund ticket %s submitted (status: %s). Refund will complete once Waffo reviews and approves the request — this is not instant.',
                $ticket['ticketId'],
                $ticket['status']
            ));

            return true;
        } catch (\WaffoPancake\Api\Waffo_Api_Exception $e) {
            // 与process_payment()不同，这里的WP_Error是给后台商户看的诊断信息（不是买家notice），
            // 展示具体错误有助于商户排查；Waffo_Api_Exception的message只来自API业务错误或本地校验
            // 失败描述，不会包含私钥/签名等敏感材料，可以安全展示。
            return new \WP_Error('waffo_refund_failed', 'Refund request failed: ' . $e->getMessage());
        }
    }
}
