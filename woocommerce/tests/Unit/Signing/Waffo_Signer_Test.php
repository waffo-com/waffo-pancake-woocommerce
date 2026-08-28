<?php
namespace WaffoPancake\Tests\Unit\Signing;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Api\Waffo_Api_Exception;
use WaffoPancake\Signing\Waffo_Signer;

class Waffo_Signer_Test extends TestCase
{
    private string $private_key;
    private string $public_key;

    protected function setUp(): void
    {
        $this->private_key = file_get_contents(__DIR__ . '/../../fixtures/test_private_key.pem');
        $this->public_key  = file_get_contents(__DIR__ . '/../../fixtures/test_public_key.pem');
    }

    public function test_sign_produces_verifiable_signature(): void
    {
        $signer = new Waffo_Signer($this->private_key);

        $signature = $signer->sign('POST', '/v1/actions/checkout/create-session', 1700000000000, '{"currency":"USD"}');

        $canonical = "POST\n/v1/actions/checkout/create-session\n1700000000000\n" . base64_encode(hash('sha256', '{"currency":"USD"}', true));

        $verified = openssl_verify(
            $canonical,
            base64_decode($signature),
            $this->public_key,
            OPENSSL_ALGO_SHA256
        );

        $this->assertSame(1, $verified);
    }

    public function test_sign_ignores_query_string_in_path(): void
    {
        $signer = new Waffo_Signer($this->private_key);

        // path 必须不含 query string —— 传入带 query string 的 path 时应自动剥离
        $signature_with_query = $signer->sign('GET', '/v1/orders/ORD_1?foo=bar', 1700000000000, '');
        $signature_without_query = $signer->sign('GET', '/v1/orders/ORD_1', 1700000000000, '');

        $this->assertSame($signature_without_query, $signature_with_query);
    }

    public function test_sign_empty_body_hashes_empty_string(): void
    {
        $signer = new Waffo_Signer($this->private_key);

        $signature = $signer->sign('GET', '/v1/orders/ORD_1', 1700000000000, '');

        $canonical = "GET\n/v1/orders/ORD_1\n1700000000000\n" . base64_encode(hash('sha256', '', true));

        $verified = openssl_verify(
            $canonical,
            base64_decode($signature),
            $this->public_key,
            OPENSSL_ALGO_SHA256
        );

        $this->assertSame(1, $verified);
    }

    public function test_sign_throws_on_invalid_private_key(): void
    {
        $signer = new Waffo_Signer('not a valid PEM key');

        $this->expectException(Waffo_Api_Exception::class);

        $signer->sign('GET', '/v1/orders/ORD_1', 1700000000000, '');
    }

    public function test_sign_is_case_insensitive_to_http_method(): void
    {
        $signer = new Waffo_Signer($this->private_key);

        $signature_lower = $signer->sign('post', '/v1/actions/checkout/create-session', 1700000000000, '{"currency":"USD"}');
        $signature_upper = $signer->sign('POST', '/v1/actions/checkout/create-session', 1700000000000, '{"currency":"USD"}');

        $this->assertSame($signature_upper, $signature_lower);
    }
}
