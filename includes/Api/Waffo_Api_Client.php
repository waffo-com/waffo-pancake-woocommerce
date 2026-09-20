<?php
namespace WaffoPancake\Api;

use WaffoPancake\Signing\Waffo_Signer;

class Waffo_Api_Client
{
    private Waffo_Signer $signer;
    private string $merchant_id;
    private string $base_url;
    /** @var callable */
    private $clock;

    public function __construct(Waffo_Signer $signer, string $merchant_id, string $base_url, ?callable $clock = null)
    {
        $this->signer = $signer;
        $this->merchant_id = $merchant_id;
        $this->base_url = rtrim($base_url, '/');
        $this->clock = $clock ?? fn () => (int) round(microtime(true) * 1000);
    }

    public function post(string $path, array $body): array
    {
        $body_json = wp_json_encode($body);

        return $this->request('POST', $path, $body_json, [
            'method'  => 'POST',
            'headers' => $this->signed_headers('POST', $path, $body_json),
            'body'    => $body_json,
            'timeout' => 15,
        ]);
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path, '', [
            'headers' => $this->signed_headers('GET', $path, ''),
            'timeout' => 15,
        ]);
    }

    /**
     * 创建收银台checkout session，供process_payment真实实现调用以获取跳转用的hosted URL。
     */
    public function create_checkout_session(array $payload): array
    {
        $response = $this->post('/v1/actions/checkout/create-session', $payload);
        return $response['data'];
    }

    /**
     * 为Customer Session Token预留，收银台前端鉴权场景，当前无调用方。
     * 插件当前设计走商户服务端直接创建checkout session拿hosted URL跳转，
     * 不需要走Customer Session Token这条路径；保留此方法以便未来该场景启用时可直接复用。
     */
    public function issue_session_token(array $payload): array
    {
        $response = $this->post('/v1/actions/auth/issue-session-token', $payload);
        return $response['data'];
    }

    /**
     * 创建退款工单，供process_refund真实实现调用。
     *
     * @param string $amount 必须用 Waffo_Money::to_display_string() 生成，不要手工拼接，
     *                        尤其零小数货币（如JPY）没有小数位，手工拼接容易出错。
     */
    public function create_refund_ticket(string $payment_id, string $amount, string $currency, string $reason): array
    {
        $response = $this->post('/v1/actions/refund-ticket/create-ticket', [
            'paymentId' => $payment_id,
            'requestedAmount' => ['amount' => $amount, 'currency' => $currency],
            'reason' => $reason,
        ]);

        return $response['data'];
    }

    /**
     * 按商户侧订单号（我们传给create-session的orderMerchantExternalId，即WC订单ID）反查
     * Waffo一次性订单，供WP-Cron兜底轮询调用。
     *
     * 为什么不用 onetimeOrder(id)：create-session 只返回 sessionId/checkoutUrl/expiresAt，
     * 插件在下单时拿不到 Waffo 订单ID（ORD_xxx），只能凭 orderMerchantExternalId 反查；
     * 而 onetimeOrders 列表查询要求 storeId，所以网关设置里需要商户填写 Store ID。
     *
     * 使用GraphQL variables参数化传参，避免订单号里的特殊字符破坏query语法或引入注入风险。
     * 返回 null 表示未找到；GraphQL层错误（即使HTTP 200）抛 Waffo_Api_Exception。
     *
     * @return array{id:string,status:string,orderMerchantExternalId?:string,payments:array<int,array{id:string,status:string}>}|null
     */
    public function find_onetime_order_by_external_id(string $store_id, string $external_id): ?array
    {
        $query = 'query FindOrderByRef($storeId: String!, $ref: String!) {'
            . ' onetimeOrders(storeId: $storeId, limit: 1, filter: { orderMerchantExternalId: { eq: $ref } }) {'
            . ' id status orderMerchantExternalId payments { id status } } }';

        $response = $this->post('/v1/graphql', [
            'query'     => $query,
            'variables' => ['storeId' => $store_id, 'ref' => $external_id],
        ]);

        if (!empty($response['errors'])) {
            $message = $response['errors'][0]['message'] ?? 'GraphQL query failed';
            throw new Waffo_Api_Exception($message, 200);
        }

        $orders = $response['data']['onetimeOrders'] ?? [];
        return $orders[0] ?? null;
    }

    private function signed_headers(string $method, string $path, string $body): array
    {
        $timestamp_ms = ($this->clock)();

        return [
            'Content-Type'  => 'application/json',
            'X-Merchant-Id' => $this->merchant_id,
            'X-Timestamp'   => (string) $timestamp_ms,
            'X-Signature'   => $this->signer->sign($method, $path, $timestamp_ms, $body),
        ];
    }

    private function request(string $method, string $path, string $body, array $args): array
    {
        $url = $this->base_url . $path;

        $response = $method === 'GET' ? wp_remote_get($url, $args) : wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            throw new Waffo_Api_Exception($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);

        if (!is_array($decoded)) {
            throw new Waffo_Api_Exception('Malformed JSON response from Waffo API', $status_code);
        }

        if ($status_code >= 400) {
            $first_error = $decoded['errors'][0] ?? null;
            $message = $first_error['message'] ?? ('Waffo API request failed with status ' . $status_code);
            $error_code = $first_error['code'] ?? null;

            // 调用方捕获此异常时，记录日志请脱敏请求/响应体中的支付凭证等敏感字段，避免泄漏到日志系统。
            throw new Waffo_Api_Exception($message, $status_code, $error_code);
        }

        return $decoded;
    }
}
