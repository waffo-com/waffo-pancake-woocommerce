<?php
namespace WaffoPancake\Api;

use WaffoPancake\Signing\Waffo_Signer;

class Waffo_Api_Client
{
    private Waffo_Signer $signer;
    private string $merchant_id;
    private string $base_url;

    public function __construct(Waffo_Signer $signer, string $merchant_id, string $base_url)
    {
        $this->signer = $signer;
        $this->merchant_id = $merchant_id;
        $this->base_url = rtrim($base_url, '/');
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

    private function signed_headers(string $method, string $path, string $body): array
    {
        $timestamp_ms = (int) round(microtime(true) * 1000);

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

            throw new Waffo_Api_Exception($message, $status_code, $error_code);
        }

        return $decoded;
    }
}
