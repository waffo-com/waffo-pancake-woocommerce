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

    public function create_checkout_session(array $payload): array
    {
        $response = $this->post('/v1/actions/checkout/create-session', $payload);
        return $response['data'];
    }

    public function issue_session_token(array $payload): array
    {
        $response = $this->post('/v1/actions/auth/issue-session-token', $payload);
        return $response['data'];
    }

    public function create_refund_ticket(string $payment_id, string $amount, string $currency, string $reason): array
    {
        $response = $this->post('/v1/actions/refund-ticket/create-ticket', [
            'paymentId' => $payment_id,
            'requestedAmount' => ['amount' => $amount, 'currency' => $currency],
            'reason' => $reason,
        ]);

        return $response['data'];
    }

    public function query_order_status(string $order_id): array
    {
        $query = 'query { onetimeOrder(id: "' . $order_id . '") { id status } }';
        $response = $this->post('/v1/graphql', ['query' => $query]);
        return $response['data']['onetimeOrder'] ?? [];
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
