<?php
namespace WaffoPancake\Signing;

class Waffo_Webhook_Verifier
{
    private const MAX_TIMESTAMP_AGE_MS = 5 * 60 * 1000; // 5分钟，参照API签名惯例（待确认，见设计文档第10节）

    private string $public_key_pem;

    public function __construct(string $public_key_pem)
    {
        $this->public_key_pem = $public_key_pem;
    }

    public function verify(string $signature_header, string $raw_body): bool
    {
        if (!preg_match('/^t=(\d+),v1=([A-Za-z0-9+\/=]+)$/', $signature_header, $matches)) {
            return false;
        }

        [, $timestamp_ms, $signature_b64] = $matches;
        $timestamp_ms = (int) $timestamp_ms;

        $now_ms = (int) (microtime(true) * 1000);
        if (abs($now_ms - $timestamp_ms) > self::MAX_TIMESTAMP_AGE_MS) {
            return false;
        }

        $signed_payload = $timestamp_ms . '.' . $raw_body;
        $signature = base64_decode($signature_b64, true);
        if ($signature === false) {
            return false;
        }

        $public_key = openssl_pkey_get_public($this->public_key_pem);
        if ($public_key === false) {
            return false;
        }

        return openssl_verify($signed_payload, $signature, $public_key, OPENSSL_ALGO_SHA256) === 1;
    }
}
