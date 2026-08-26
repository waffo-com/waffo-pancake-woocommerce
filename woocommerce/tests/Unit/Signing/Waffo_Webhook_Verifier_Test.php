<?php
namespace WaffoPancake\Tests\Unit\Signing;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Signing\Waffo_Webhook_Verifier;

class Waffo_Webhook_Verifier_Test extends TestCase
{
    private string $private_key;
    private string $public_key;

    protected function setUp(): void
    {
        $this->private_key = file_get_contents(__DIR__ . '/../../fixtures/test_private_key.pem');
        $this->public_key  = file_get_contents(__DIR__ . '/../../fixtures/test_public_key.pem');
    }

    private function build_header(string $raw_body, int $timestamp_ms): string
    {
        $signed_payload = $timestamp_ms . '.' . $raw_body;
        $private_key = openssl_pkey_get_private($this->private_key);
        openssl_sign($signed_payload, $signature, $private_key, OPENSSL_ALGO_SHA256);

        return 't=' . $timestamp_ms . ',v1=' . base64_encode($signature);
    }

    public function test_verify_accepts_valid_signature(): void
    {
        $raw_body = '{"eventId":"PAY_1","eventType":"order.completed"}';
        $timestamp_ms = (int) (microtime(true) * 1000);
        $header = $this->build_header($raw_body, $timestamp_ms);

        $verifier = new Waffo_Webhook_Verifier($this->public_key);

        $this->assertTrue($verifier->verify($header, $raw_body));
    }

    public function test_verify_rejects_tampered_body(): void
    {
        $raw_body = '{"eventId":"PAY_1","eventType":"order.completed"}';
        $timestamp_ms = (int) (microtime(true) * 1000);
        $header = $this->build_header($raw_body, $timestamp_ms);

        $verifier = new Waffo_Webhook_Verifier($this->public_key);

        $tampered_body = '{"eventId":"PAY_1","eventType":"order.refunded"}';

        $this->assertFalse($verifier->verify($header, $tampered_body));
    }

    public function test_verify_rejects_stale_timestamp(): void
    {
        $raw_body = '{"eventId":"PAY_1"}';
        $ten_minutes_ago_ms = (int) (microtime(true) * 1000) - (10 * 60 * 1000);
        $header = $this->build_header($raw_body, $ten_minutes_ago_ms);

        $verifier = new Waffo_Webhook_Verifier($this->public_key);

        $this->assertFalse($verifier->verify($header, $raw_body));
    }

    public function test_verify_rejects_malformed_header(): void
    {
        $verifier = new Waffo_Webhook_Verifier($this->public_key);

        $this->assertFalse($verifier->verify('not-a-valid-header', '{}'));
    }
}
