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

    public function test_create_checkout_session_posts_expected_payload(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        \WP_Mock::userFunction('wp_remote_post')
            ->once()
            ->with(
                'https://api.test.waffo.ai/v1/actions/checkout/create-session',
                \Mockery::on(function ($args) {
                    $decoded = json_decode($args['body'], true);
                    return $decoded['productId'] === 'PROD_1'
                        && $decoded['currency'] === 'USD'
                        && $decoded['orderMerchantExternalId'] === 'wc_order_42'
                        && $decoded['successUrl'] === 'https://shop.example.com/thank-you';
                })
            )
            ->andReturn(['response' => ['code' => 200], 'body' => '{"data":{"sessionId":"cs_1","checkoutUrl":"https://pancake.waffo.ai/x","expiresAt":"2026-01-01T00:00:00Z"}}']);

        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn('{"data":{"sessionId":"cs_1","checkoutUrl":"https://pancake.waffo.ai/x","expiresAt":"2026-01-01T00:00:00Z"}}');

        $client = $this->make_client();

        $result = $client->create_checkout_session([
            'productId' => 'PROD_1',
            'currency' => 'USD',
            'orderMerchantExternalId' => 'wc_order_42',
            'successUrl' => 'https://shop.example.com/thank-you',
        ]);

        $this->assertSame('https://pancake.waffo.ai/x', $result['checkoutUrl']);
    }

    public function test_create_refund_ticket_posts_expected_payload(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        \WP_Mock::userFunction('wp_remote_post')
            ->once()
            ->with(
                'https://api.test.waffo.ai/v1/actions/refund-ticket/create-ticket',
                \Mockery::on(function ($args) {
                    $decoded = json_decode($args['body'], true);
                    return $decoded['paymentId'] === 'PAY_1' && $decoded['requestedAmount']['amount'] === '10.00';
                })
            )
            ->andReturn(['response' => ['code' => 200], 'body' => '{"data":{"ticketId":"RFT_1","status":"pending"}}']);

        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn('{"data":{"ticketId":"RFT_1","status":"pending"}}');

        $client = $this->make_client();

        $result = $client->create_refund_ticket('PAY_1', '10.00', 'USD', 'Customer requested refund');

        $this->assertSame('RFT_1', $result['ticketId']);
        $this->assertSame('pending', $result['status']);
    }

    public function test_find_onetime_order_by_external_id_queries_by_store_and_ref(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        $body = '{"data":{"onetimeOrders":[{"id":"ORD_1","status":"completed","orderMerchantExternalId":"42","payments":[{"id":"PAY_failed","status":"failed"},{"id":"PAY_ok","status":"succeeded"}]}]}}';

        \WP_Mock::userFunction('wp_remote_post')
            ->once()
            ->with(
                'https://api.test.waffo.ai/v1/graphql',
                \Mockery::on(function ($args) {
                    $decoded = json_decode($args['body'], true);
                    // 变量化传参：query文本里不得内联任何用户可控值，storeId/ref都走variables
                    return strpos($decoded['query'], '$storeId') !== false
                        && strpos($decoded['query'], '$ref') !== false
                        && strpos($decoded['query'], '"') === false
                        && $decoded['variables'] === ['storeId' => 'STO_1', 'ref' => '42"}_injected'];
                })
            )
            ->andReturn(['response' => ['code' => 200], 'body' => $body]);

        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn($body);

        $client = $this->make_client();

        $result = $client->find_onetime_order_by_external_id('STO_1', '42"}_injected');

        $this->assertSame('ORD_1', $result['id']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('PAY_ok', $result['payments'][1]['id']);
    }

    public function test_find_onetime_order_by_external_id_returns_null_when_not_found(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        $body = '{"data":{"onetimeOrders":[]}}';
        \WP_Mock::userFunction('wp_remote_post')->once()->andReturn(['response' => ['code' => 200], 'body' => $body]);
        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn($body);

        $this->assertNull($this->make_client()->find_onetime_order_by_external_id('STO_1', '404'));
    }

    public function test_find_onetime_order_by_external_id_throws_on_graphql_errors_with_200(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        // GraphQL 语义错误通常伴随 HTTP 200，必须单独识别 errors 数组，否则会被当成"未找到"静默吞掉
        $body = '{"errors":[{"message":"Expected format: STO_xxx, got \"bad\""}],"data":null}';
        \WP_Mock::userFunction('wp_remote_post')->once()->andReturn(['response' => ['code' => 200], 'body' => $body]);
        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn($body);

        $this->expectException(Waffo_Api_Exception::class);
        $this->expectExceptionMessage('Expected format');
        $this->make_client()->find_onetime_order_by_external_id('bad', '1');
    }

    public function test_issue_session_token_forwards_payload_and_returns_data(): void
    {
        \WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(fn ($value) => json_encode($value));

        \WP_Mock::userFunction('wp_remote_post')
            ->once()
            ->with(
                'https://api.test.waffo.ai/v1/actions/auth/issue-session-token',
                \Mockery::on(function ($args) {
                    $decoded = json_decode($args['body'], true);
                    return $decoded['customerId'] === 'CUST_1';
                })
            )
            ->andReturn(['response' => ['code' => 200], 'body' => '{"data":{"sessionToken":"tok_1"}}']);

        \WP_Mock::userFunction('is_wp_error')->andReturn(false);
        \WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(200);
        \WP_Mock::userFunction('wp_remote_retrieve_body')->andReturn('{"data":{"sessionToken":"tok_1"}}');

        $client = $this->make_client();

        $result = $client->issue_session_token(['customerId' => 'CUST_1']);

        $this->assertSame('tok_1', $result['sessionToken']);
    }
}
