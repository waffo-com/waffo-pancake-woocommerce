<?php
namespace WaffoPancake\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Api\Waffo_Api_Client;
use WaffoPancake\Api\Waffo_Api_Exception;
use WaffoPancake\Signing\Waffo_Signer;

class Waffo_Api_Client_Test extends TestCase
{
    private string $private_key;

    protected function setUp(): void
    {
        \WP_Mock::setUp();
        $this->private_key = file_get_contents(__DIR__ . '/../../fixtures/test_private_key.pem');
    }

    protected function tearDown(): void
    {
        \WP_Mock::tearDown();
    }

    private function make_client(string $base_url = 'https://api.test.waffo.ai', ?callable $clock = null): Waffo_Api_Client
    {
        $signer = new Waffo_Signer($this->private_key);
        return new Waffo_Api_Client($signer, 'MER_test123', $base_url, $clock);
    }

    public function test_post_sends_signed_headers_and_returns_decoded_body(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        \WP_Mock::userFunction('wp_remote_post')
            ->once()
            ->with(
                'https://api.test.waffo.ai/v1/actions/checkout/create-session',
                \Mockery::on(function ($args) {
                    return $args['headers']['Content-Type'] === 'application/json'
                        && $args['headers']['X-Merchant-Id'] === 'MER_test123'
                        && isset($args['headers']['X-Timestamp'])
                        && isset($args['headers']['X-Signature'])
                        && $args['body'] === '{"currency":"USD"}';
                })
            )
            ->andReturn(['response' => ['code' => 200], 'body' => '{"data":{"sessionId":"cs_1","checkoutUrl":"https://pancake.waffo.ai/x"}}']);

        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn('{"data":{"sessionId":"cs_1","checkoutUrl":"https://pancake.waffo.ai/x"}}');

        $client = $this->make_client();

        $result = $client->post('/v1/actions/checkout/create-session', ['currency' => 'USD']);

        $this->assertSame('cs_1', $result['data']['sessionId']);
        $this->assertSame('https://pancake.waffo.ai/x', $result['data']['checkoutUrl']);
    }

    public function test_post_throws_on_wp_error(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        \WP_Mock::userFunction('wp_remote_post')->andReturn(new \WP_Error('http_request_failed', 'Connection timed out'));
        \WP_Mock::userFunction('is_wp_error')->andReturn(true);

        $client = $this->make_client();

        $this->expectException(Waffo_Api_Exception::class);
        $this->expectExceptionMessage('Connection timed out');

        $client->post('/v1/actions/checkout/create-session', []);
    }

    public function test_post_throws_on_4xx_with_api_error_body(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        \WP_Mock::userFunction('wp_remote_post')->andReturn(['response' => ['code' => 400], 'body' => '{"errors":[{"code":"invalid_currency","message":"Unsupported currency"}]}']);
        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(400);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn('{"errors":[{"code":"invalid_currency","message":"Unsupported currency"}]}');

        $client = $this->make_client();

        try {
            $client->post('/v1/actions/checkout/create-session', ['currency' => 'XXX']);
            $this->fail('Expected Waffo_Api_Exception');
        } catch (Waffo_Api_Exception $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('Unsupported currency', $e->getMessage());
            $this->assertSame('invalid_currency', $e->getErrorCode());
        }
    }

    public function test_post_throws_on_malformed_json_response(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        \WP_Mock::userFunction('wp_remote_post')->andReturn(['response' => ['code' => 200], 'body' => 'not json']);
        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn('not json');

        $client = $this->make_client();

        $this->expectException(Waffo_Api_Exception::class);

        $client->post('/v1/actions/checkout/create-session', []);
    }

    public function test_get_sends_signed_request_with_empty_body_hash(): void
    {
        \WP_Mock::userFunction('wp_remote_get')
            ->once()
            ->with(
                'https://api.test.waffo.ai/v1/graphql?query=x',
                \Mockery::on(fn ($args) => isset($args['headers']['X-Signature']))
            )
            ->andReturn(['response' => ['code' => 200], 'body' => '{"data":{}}']);

        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn('{"data":{}}');

        $client = $this->make_client();

        $result = $client->get('/v1/graphql?query=x');

        $this->assertSame([], $result['data']);
    }

    public function test_injected_clock_produces_predictable_timestamp(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        $captured_args = null;

        \WP_Mock::userFunction('wp_remote_post')
            ->once()
            ->andReturnUsing(function ($url, $args) use (&$captured_args) {
                $captured_args = $args;
                return ['response' => ['code' => 200], 'body' => '{"data":{}}'];
            });

        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn('{"data":{}}');

        $client = $this->make_client('https://api.test.waffo.ai', fn () => 1700000000000);

        $client->post('/v1/actions/checkout/create-session', []);

        $this->assertSame('1700000000000', $captured_args['headers']['X-Timestamp']);
    }

    public function test_is_retryable_reflects_status_code(): void
    {
        $this->assertTrue((new Waffo_Api_Exception('Server error', 500))->is_retryable());
        $this->assertTrue((new Waffo_Api_Exception('Too many requests', 429))->is_retryable());
        $this->assertFalse((new Waffo_Api_Exception('Bad request', 400))->is_retryable());
    }
}
