# Waffo Pancake WooCommerce 插件 — 真实API接入实现计划

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 把骨架里的占位逻辑替换成真实的 Waffo Pancake API 调用：`Waffo_Api_Client`（RSA签名请求 + checkout session/order创建 + GraphQL兜底查询 + 退款）、webhook REST端点（内置公钥验签）、真实的 `process_payment`/`process_refund`、商品编辑页的WC商品↔Waffo商品映射字段UI、WP-Cron兜底轮询。同时清偿骨架实现计划 Task 8 清单里的10条跟进项。

**Architecture:** 沿用骨架阶段确立的分层：`includes/Signing/`（签名/验签，已完成）、`includes/Money/`（金额换算，已完成）、`includes/Dedup/`（去重，已完成）、`includes/Order/`（状态映射，已完成）新增 `includes/Api/`（REST客户端）、`includes/Webhook/`（REST端点控制器）、`includes/Cron/`（兜底轮询）。纯逻辑部分继续走PHPUnit+WP_Mock单元测试；`WC_Gateway_Waffo_Pancake` 里新增的方法因依赖`WC_Payment_Gateway`/`WC_Order`基类，同前次任务只做`php -l`语法检查，不强行mock整个WooCommerce订单模型。

**Tech Stack:** PHP 8.1+，PHPUnit 9.6，WP_Mock，Composer，WordPress REST API（`register_rest_route`），WP-Cron。

**依据的调研结论**（来自本次真实API核实，权威来源 `~/Projects/waffo-pancake-docs`）：
1. 商户认证：`POST /v1/actions/checkout/create-session`，API Key模式用 `X-Merchant-Id`/`X-Timestamp`/`X-Signature`（RSA-SHA256，复用已实现的`Waffo_Signer`），无需`X-Environment`头。
2. `POST /v1/actions/auth/issue-session-token`：API Key认证，请求体`{storeId?, productId?, buyerIdentity}`，响应`{token, expiresAt}`，用于换取给收银台前端用的Customer Session Token。
3. Webhook验签公钥是**固定值**（test/live各一把，硬编码在官方SDK和Dashboard前端），不需要商户手动配置，也不需要拉取接口。**本计划里用占位符 `WAFFO_PUBLIC_KEY_TEST_PLACEHOLDER`/`WAFFO_PUBLIC_KEY_LIVE_PLACEHOLDER` 标记，你需要从 `~/Projects/Waffo-pancake-dashboard/src/lib/api/webhook-keys.ts` 拷贝真实PEM值替换。**
4. GraphQL `/v1/graphql` 支持API Key（RSA签名）认证，可用于Cron兜底轮询查订单状态，无需走JWT登录。
5. Webhook重试：最多3次重试（共4次尝试），指数退避，最终失败标记为`failed`。
6. 官方SDK `@waffo/pancake-ts` 的请求签名、错误结构（`WaffoPancakeError`：`status`/`errors`/`errors[0].layer`）、幂等键生成（`merchantId + path + body`决定性哈希）是PHP实现的行为参照基准。

---

### Task 1: `Waffo_Api_Client` — REST请求基础设施（签名注入 + 错误处理）

**Files:**
- Create: `includes/Api/Waffo_Api_Exception.php`
- Create: `includes/Api/Waffo_Api_Client.php`
- Test: `tests/Unit/Api/Waffo_Api_Client_Test.php`

**Step 1: 写失败的测试**

用WP_Mock模拟`wp_remote_post`/`wp_remote_get`/`wp_remote_retrieve_body`/`wp_remote_retrieve_response_code`/`is_wp_error`，不发真实网络请求。

```php
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

    private function make_client(string $base_url = 'https://api.test.waffo.ai'): Waffo_Api_Client
    {
        $signer = new Waffo_Signer($this->private_key);
        return new Waffo_Api_Client($signer, 'MER_test123', $base_url);
    }

    public function test_post_sends_signed_headers_and_returns_decoded_body(): void
    {
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
        \WP_Mock::userFunction('wp_remote_post')->andReturn(new \WP_Error('http_request_failed', 'Connection timed out'));
        \WP_Mock::userFunction('is_wp_error')->andReturn(true);

        $client = $this->make_client();

        $this->expectException(Waffo_Api_Exception::class);
        $this->expectExceptionMessage('Connection timed out');

        $client->post('/v1/actions/checkout/create-session', []);
    }

    public function test_post_throws_on_4xx_with_api_error_body(): void
    {
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
}
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Waffo_Api_Client_Test`
Expected: FAIL，class not found

**Step 3: 写实现**

`includes/Api/Waffo_Api_Exception.php`：

```php
<?php
namespace WaffoPancake\Api;

class Waffo_Api_Exception extends \RuntimeException
{
    private int $status_code;
    private ?string $error_code;

    public function __construct(string $message, int $status_code = 0, ?string $error_code = null)
    {
        parent::__construct($message);
        $this->status_code = $status_code;
        $this->error_code = $error_code;
    }

    public function getStatusCode(): int
    {
        return $this->status_code;
    }

    public function getErrorCode(): ?string
    {
        return $this->error_code;
    }
}
```

`includes/Api/Waffo_Api_Client.php`：

```php
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
```

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter Waffo_Api_Client_Test`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add includes/Api/ tests/Unit/Api/
git commit -m "feat: 实现Waffo_Api_Client签名请求客户端"
```

---

### Task 2: `Waffo_Api_Client` 扩展 — checkout session / issue-session-token / 退款 / GraphQL查询方法

**Files:**
- Modify: `includes/Api/Waffo_Api_Client.php`
- Test: `tests/Unit/Api/Waffo_Api_Client_Test.php`（追加测试）

**Step 1: 追加失败的测试**

```php
    public function test_create_checkout_session_posts_expected_payload(): void
    {
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
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Waffo_Api_Client_Test`
Expected: FAIL，method not found

**Step 3: 在`Waffo_Api_Client`里追加方法**

```php
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
```

注：`query_order_status` 用 POST（GraphQL约定走POST而非GET），这里没有走`get()`方法，Task 1里定义的`get()`留给未来其他真正的GET端点用，不算未使用代码——如果code review认为当前`get()`确无调用方、属于过早引入，可以在这次review阶段讨论是否移除，不要自行决定。

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter Waffo_Api_Client_Test`
Expected: PASS (7 tests)

**Step 5: Commit**

```bash
git add includes/Api/Waffo_Api_Client.php tests/Unit/Api/Waffo_Api_Client_Test.php
git commit -m "feat: Waffo_Api_Client补充checkout/退款/订单查询方法"
```

---

### Task 3: 内置Webhook公钥常量

**Files:**
- Create: `includes/Signing/Waffo_Webhook_Public_Keys.php`
- Test: `tests/Unit/Signing/Waffo_Webhook_Public_Keys_Test.php`

**⚠️ 本任务包含需要人工填入真实值的占位符，见Step 3。**

**Step 1: 写失败的测试**

```php
<?php
namespace WaffoPancake\Tests\Unit\Signing;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Signing\Waffo_Webhook_Public_Keys;

class Waffo_Webhook_Public_Keys_Test extends TestCase
{
    public function test_for_mode_returns_test_key(): void
    {
        $key = Waffo_Webhook_Public_Keys::for_mode('test');
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $key);
    }

    public function test_for_mode_returns_live_key(): void
    {
        $key = Waffo_Webhook_Public_Keys::for_mode('prod');
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $key);
    }

    public function test_for_mode_throws_on_unknown_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Waffo_Webhook_Public_Keys::for_mode('staging');
    }

    public function test_keys_are_valid_pem_public_keys(): void
    {
        $test_key = openssl_pkey_get_public(Waffo_Webhook_Public_Keys::for_mode('test'));
        $live_key = openssl_pkey_get_public(Waffo_Webhook_Public_Keys::for_mode('prod'));

        $this->assertNotFalse($test_key, 'test公钥必须是合法PEM格式，占位符需替换为真实值');
        $this->assertNotFalse($live_key, 'live公钥必须是合法PEM格式，占位符需替换为真实值');
    }
}
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Waffo_Webhook_Public_Keys_Test`
Expected: FAIL，class not found

**Step 3: 写实现（含需要人工替换的占位符）**

```php
<?php
namespace WaffoPancake\Signing;

class Waffo_Webhook_Public_Keys
{
    // ⚠️ 占位符，需要从 Waffo Dashboard（或 ~/Projects/Waffo-pancake-dashboard/
    // src/lib/api/webhook-keys.ts）拷贝真实PEM公钥替换，否则验签会全部失败。
    // 这两把是Waffo平台侧固定公钥，全部商户共用，不需要商户自行配置。
    private const TEST_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
REPLACE_WITH_REAL_TEST_PUBLIC_KEY_FROM_WAFFO_DASHBOARD
-----END PUBLIC KEY-----
PEM;

    private const LIVE_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
REPLACE_WITH_REAL_LIVE_PUBLIC_KEY_FROM_WAFFO_DASHBOARD
-----END PUBLIC KEY-----
PEM;

    public static function for_mode(string $mode): string
    {
        return match ($mode) {
            'test' => self::TEST_KEY,
            'prod' => self::LIVE_KEY,
            default => throw new \InvalidArgumentException('Unknown Waffo environment mode: ' . $mode),
        };
    }
}
```

**注意**：Step 1里的`test_keys_are_valid_pem_public_keys`测试在占位符未被替换前**会失败**，这是有意为之——用测试强制提醒必须替换真实值才能让CI/本地测试全绿，不要为了让测试通过而删除或放宽这个断言。

**Step 4: 运行测试**

Run: `composer test -- --filter Waffo_Webhook_Public_Keys_Test`
Expected: 前3个测试通过，第4个测试`test_keys_are_valid_pem_public_keys`失败（因为占位符不是合法PEM）。**这是预期状态，不要为了让它通过而伪造一对随手生成的密钥**——必须是Waffo真实公钥，否则接入真实环境时验签会全部失败但测试却是绿的，产生虚假的安全感。commit时在commit message里明确注明这个已知失败项，等真实值填入后由人工替换并重新验证。

**Step 5: Commit**

```bash
git add includes/Signing/Waffo_Webhook_Public_Keys.php tests/Unit/Signing/Waffo_Webhook_Public_Keys_Test.php
git commit -m "feat: 内置webhook验签公钥常量（占位符待替换为真实PEM值）"
```

---

### Task 4: Webhook REST端点控制器

**Files:**
- Create: `includes/Webhook/Waffo_Webhook_Controller.php`
- Test: `tests/Unit/Webhook/Waffo_Webhook_Controller_Test.php`

**Step 1: 写失败的测试**

用WP_Mock模拟`register_rest_route`注册逻辑和`WP_REST_Request`/`WP_REST_Response`交互。

```php
<?php
namespace WaffoPancake\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Webhook\Waffo_Webhook_Controller;
use WaffoPancake\Signing\Waffo_Webhook_Verifier;
use WaffoPancake\Dedup\Waffo_Event_Deduplicator;
use WaffoPancake\Dedup\In_Memory_Event_Store;

class Waffo_Webhook_Controller_Test extends TestCase
{
    protected function setUp(): void
    {
        \WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        \WP_Mock::tearDown();
    }

    private function make_controller(Waffo_Webhook_Verifier $verifier, Waffo_Event_Deduplicator $dedup): Waffo_Webhook_Controller
    {
        $handled = [];
        return new Waffo_Webhook_Controller($verifier, $dedup, function (array $event) use (&$handled) {
            $handled[] = $event;
            return $handled;
        });
    }

    public function test_register_routes_registers_expected_route(): void
    {
        \WP_Mock::expectFilterAdded('rest_api_init', \Mockery::type('array'));

        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());
        $controller = $this->make_controller($verifier, $dedup);

        $controller->register_routes();

        $this->assertConditionsMet();
    }

    public function test_handle_returns_401_on_invalid_signature(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(false);
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $controller = $this->make_controller($verifier, $dedup);

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=bad');
        $request->shouldReceive('get_body')->andReturn('{"eventId":"PAY_1"}');

        \WP_Mock::userFunction('rest_ensure_response')->andReturnUsing(fn ($data) => $data);

        $response = $controller->handle($request);

        $this->assertSame(401, $response['status'] ?? null);
    }

    public function test_handle_returns_200_and_skips_processing_on_duplicate_event(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);

        $store = new In_Memory_Event_Store();
        $dedup = new Waffo_Event_Deduplicator($store);
        $dedup->mark_processed('PAY_1');

        $processed = [];
        $controller = new Waffo_Webhook_Controller($verifier, $dedup, function (array $event) use (&$processed) {
            $processed[] = $event;
        });

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('{"eventId":"PAY_1","eventType":"order.completed"}');

        \WP_Mock::userFunction('rest_ensure_response')->andReturnUsing(fn ($data) => $data);

        $response = $controller->handle($request);

        $this->assertSame(200, $response['status'] ?? null);
        $this->assertEmpty($processed, '重复事件不应触发处理回调');
    }

    public function test_handle_processes_new_event_and_marks_it_seen(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);

        $store = new In_Memory_Event_Store();
        $dedup = new Waffo_Event_Deduplicator($store);

        $processed = [];
        $controller = new Waffo_Webhook_Controller($verifier, $dedup, function (array $event) use (&$processed) {
            $processed[] = $event;
        });

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('{"eventId":"PAY_2","eventType":"order.completed"}');

        \WP_Mock::userFunction('rest_ensure_response')->andReturnUsing(fn ($data) => $data);

        $response = $controller->handle($request);

        $this->assertSame(200, $response['status'] ?? null);
        $this->assertCount(1, $processed);
        $this->assertSame('PAY_2', $processed[0]['eventId']);
        $this->assertTrue($dedup->is_duplicate('PAY_2'));
    }

    public function test_handle_returns_400_on_malformed_json_body(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $controller = $this->make_controller($verifier, $dedup);

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('not json');

        \WP_Mock::userFunction('rest_ensure_response')->andReturnUsing(fn ($data) => $data);

        $response = $controller->handle($request);

        $this->assertSame(400, $response['status'] ?? null);
    }
}
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Waffo_Webhook_Controller_Test`
Expected: FAIL，class not found

**Step 3: 写实现**

```php
<?php
namespace WaffoPancake\Webhook;

use WaffoPancake\Signing\Waffo_Webhook_Verifier;
use WaffoPancake\Dedup\Waffo_Event_Deduplicator;

class Waffo_Webhook_Controller
{
    private Waffo_Webhook_Verifier $verifier;
    private Waffo_Event_Deduplicator $dedup;
    /** @var callable */
    private $on_event;

    public function __construct(Waffo_Webhook_Verifier $verifier, Waffo_Event_Deduplicator $dedup, callable $on_event)
    {
        $this->verifier = $verifier;
        $this->dedup = $dedup;
        $this->on_event = $on_event;
    }

    public function register_routes(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('waffo-pancake/v1', '/webhook', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle($request)
    {
        $signature_header = $request->get_header('X-Waffo-Signature') ?? '';
        $raw_body = $request->get_body();

        if (!$this->verifier->verify($signature_header, $raw_body)) {
            return rest_ensure_response(['status' => 401, 'message' => 'Invalid webhook signature']);
        }

        $event = json_decode($raw_body, true);
        if (!is_array($event) || !isset($event['eventId'])) {
            return rest_ensure_response(['status' => 400, 'message' => 'Malformed webhook payload']);
        }

        if ($this->dedup->is_duplicate($event['eventId'])) {
            return rest_ensure_response(['status' => 200, 'message' => 'Duplicate event, already processed']);
        }

        ($this->on_event)($event);
        $this->dedup->mark_processed($event['eventId']);

        return rest_ensure_response(['status' => 200, 'message' => 'ok']);
    }
}
```

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter Waffo_Webhook_Controller_Test`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add includes/Webhook/ tests/Unit/Webhook/
git commit -m "feat: 实现webhook REST端点控制器（验签+去重+分发）"
```

---

### Task 5: 网关设置字段调整 — 私钥字段改为password类型，移除公钥输入框（清偿准入条件#9）

**Files:**
- Modify: `includes/Gateway/class-wc-gateway-waffo-pancake.php`

**Step 1: 修改`init_form_fields()`**

把：
```php
            'private_key' => [
                'title'       => 'Private Key (PEM)',
                'type'        => 'textarea',
                'description' => 'RSA private key generated in Waffo Dashboard. Kept confidential.',
            ],
            'waffo_public_key' => [
                'title'       => 'Waffo Public Key (PEM)',
                'type'        => 'textarea',
                'description' => 'Used to verify webhook signatures. (待确认：官方公钥获取渠道，见设计文档第10节)',
            ],
```

改为：
```php
            'private_key' => [
                'title'       => 'Private Key (PEM)',
                'type'        => 'password',
                'description' => 'RSA private key generated in Waffo Dashboard. Stored encrypted at rest is recommended; never logged or displayed in plaintext after initial entry.',
            ],
```

（`waffo_public_key`字段整体删除——webhook验签公钥已内置为`Waffo_Webhook_Public_Keys`常量，不再需要商户配置，见Task 3）

**Step 2: 手动验证语法**

Run: `php -l includes/Gateway/class-wc-gateway-waffo-pancake.php`
Expected: `No syntax errors detected`

**Step 3: 运行全量测试确认无回归**

Run: `composer test`
Expected: 所有测试通过（这个文件不在PHPUnit覆盖范围内，此步骤只是确认没有连带破坏其他测试）

**Step 4: Commit**

```bash
git add includes/Gateway/class-wc-gateway-waffo-pancake.php
git commit -m "fix: 私钥字段改为password类型，移除多余的公钥配置项（清偿准入条件#9）"
```

**注意**：WooCommerce的`password`类型设置字段在重新渲染设置页时**仍然会把已保存的值输出到`value`属性里**（这是WC Settings API的已知行为，只是把`<textarea>`换成`<input type="password">`不能完全解决"明文存在于HTML源码"的问题，只是防止了肉眼直接看到内容、且不会被大多数浏览器的"查看页面源码"之外的途径轻易读取）。如果code review认为这还不够、需要"留空保留原值"的自定义渲染逻辑，请在review阶段讨论决定是否要在本计划范围内追加实现，还是作为已知限制记录到跟进清单。

---

### Task 6: `process_payment()` 真实实现（清偿准入条件#10）

**Files:**
- Modify: `includes/Gateway/class-wc-gateway-waffo-pancake.php`

**Step 1: 替换`process_payment()`实现**

```php
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
            wc_add_notice('Payment could not be started: ' . $e->getMessage(), 'error');
            return ['result' => 'fail'];
        }
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
        // TODO: 需要设计WooCommerce商品与Waffo商品(productId)的映射关系
        // ——是让商户在每个WC商品的编辑页填一个自定义字段，还是走别的映射方案，
        // 待与产品/业务方确认后实现。目前先从订单第一个item的商品元数据取，
        // 假设商户会填 _waffo_product_id 这个自定义字段（占位约定，需要与设置页/文档同步）。
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $waffo_product_id = $product->get_meta('_waffo_product_id');
                if ($waffo_product_id) {
                    return $waffo_product_id;
                }
            }
        }

        throw new \WaffoPancake\Api\Waffo_Api_Exception('No Waffo product mapping found for this order. Configure "_waffo_product_id" on the product.');
    }
```

**Step 2: 语法验证**

Run: `php -l includes/Gateway/class-wc-gateway-waffo-pancake.php`
Expected: `No syntax errors detected`

**Step 3: 全量测试确认无回归**

Run: `composer test`

**Step 4: Commit**

```bash
git add includes/Gateway/class-wc-gateway-waffo-pancake.php
git commit -m "feat: process_payment真实接入create-checkout-session（清偿准入条件#10）"
```

**方案确认（2026-08-28）**：WooCommerce商品↔Waffo商品的映射方式已定案为**商品自定义字段`_waffo_product_id`，商户在每个WC商品编辑页手动填写**。这不是临时占位，是正式方案——调研了Paddle/Lemon Squeezy等同为Merchant-of-Record模式的WooCommerce插件生态（Lemon Squeezy的GrandPlugins插件是已核实的确证案例），业界标准做法就是这种显式、逐商品手动填写的自定义字段，而非自动按SKU匹配或全自动同步：MoR平台的第三方商品记录通常绑定税务分类、发票文案等，出错有合规风险，不适合做成商户看不见的黑盒自动映射。`resolve_waffo_product_id()`这里的实现方式已经是最终形态，不需要再改。真正缺失的是**商品编辑页里让商户能填写这个字段的UI**，见新增的Task 6.5。

---

### Task 6.5: 商品编辑页添加 `_waffo_product_id` 自定义字段UI

**Files:**
- Create: `includes/Product/Waffo_Product_Fields.php`

这部分依赖WooCommerce的`WC_Product`/后台产品编辑页渲染钩子，无法在纯PHPUnit环境实例化验证，同Gateway任务一样只做`php -l`语法检查，遵循调研确认的WooCommerce标准写法（`woocommerce_product_options_general_product_data` + `woocommerce_process_product_meta`钩子，这是Lemon Squeezy生态插件和Meta官方WooCommerce插件共用的标准机制）。

**Step 1: 编写实现**

```php
<?php
namespace WaffoPancake\Product;

if (!defined('ABSPATH')) {
    exit;
}

class Waffo_Product_Fields
{
    public static function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'render_field']);
        add_action('woocommerce_process_product_meta', [self::class, 'save_field']);
    }

    public static function render_field(): void
    {
        global $post;

        woocommerce_wp_text_input([
            'id'          => '_waffo_product_id',
            'label'       => 'Waffo Product ID',
            'description' => 'The corresponding product ID (PROD_xxx) created in your Waffo Dashboard. Required to accept payments for this product via Waffo Pancake.',
            'desc_tip'    => true,
            'value'       => get_post_meta($post->ID, '_waffo_product_id', true),
        ]);
    }

    public static function save_field(int $post_id): void
    {
        if (isset($_POST['_waffo_product_id'])) {
            update_post_meta($post_id, '_waffo_product_id', sanitize_text_field(wp_unslash($_POST['_waffo_product_id'])));
        }
    }
}
```

**Step 2: 在插件入口注册**

在 `waffo-pancake-woocommerce.php` 的 `plugins_loaded` 回调里追加：

```php
    require_once WAFFO_PANCAKE_WC_PLUGIN_DIR . 'includes/Product/Waffo_Product_Fields.php';
    \WaffoPancake\Product\Waffo_Product_Fields::register();
```

**Step 3: 语法验证**

Run: `php -l includes/Product/Waffo_Product_Fields.php && php -l waffo-pancake-woocommerce.php`
Expected: `No syntax errors detected` ×2

**Step 4: 全量测试确认无回归**

Run: `composer test`

**Step 5: Commit**

```bash
git add includes/Product/ waffo-pancake-woocommerce.php
git commit -m "feat: 商品编辑页添加Waffo Product ID映射字段"
```

**安全说明**：`save_field()`用了`sanitize_text_field(wp_unslash(...))`是WordPress处理`$_POST`用户输入的标准安全写法（防止存储型XSS/保留原始引号转义前的内容），保存时机在`woocommerce_process_product_meta`钩子里，WooCommerce核心已经在这个钩子触发前做了nonce校验，不需要在这里重复校验nonce。

**已知限制（2026-08-28确认，v1不修复）**：variable product（多变体商品）的每个variation无法单独配置`_waffo_product_id`，v1场景聚焦虚拟商品，业务方确认不需要支持variable product，已在字段description和`resolve_waffo_product_id()`异常信息里做了明确提示。

---

### Task 6.6: `process_payment()` 支持动态价格覆盖（`priceSnapshot`）

**背景**：调研确认Waffo的`create-checkout-session`接口支持`priceSnapshot`字段，可以在下单时动态指定/覆盖本次交易的实际收费金额，而不是完全依赖Waffo后台商品配置的固定价格。这对虚拟商品的自定义金额、打赏、可变价格场景是必需的。

**关键约束（务必遵守，否则会引入价格篡改漏洞）**：
- `priceSnapshot`只在**API Key（商户/服务端）认证**下生效，Store Slug（访客/前端直连）认证会被Waffo后端静默忽略——我们的插件本来就是走商户API Key路径（`Waffo_Api_Client`用RSA签名），符合这个前提，不需要额外改造认证方式。
- `priceSnapshot.amount`是**显示格式的十进制字符串**（如`"29.00"`），不是分为单位的整数，必须用骨架阶段已实现的`Waffo_Money::to_display_string()`从WC订单的分为单位金额转换而来，不要手工拼接字符串（容易在货币精度/零小数货币场景出错，骨架计划Task 8清单第7条已经记录了`Waffo_Money`接入真实流程前的输入校验待办，本任务不重复处理那部分，只确保调用方式正确）。
- 这个字段必须只能由服务端（本插件的PHP代码）构造并通过签名请求发送，绝不能允许任何客户端可控的原始值未经服务端验证直接透传成`priceSnapshot.amount`——本任务里金额始终来自`$order->get_total()`（WooCommerce服务端计算好的订单总额，不是用户表单直接提交的数字），符合这个安全要求。

**Files:**
- Modify: `includes/Api/Waffo_Api_Client.php`（`create_checkout_session`方法本身不需要改，`priceSnapshot`作为payload的一部分透传即可，只需确认现有实现不会过滤掉未在文档示例里出现的字段）
- Modify: `includes/Gateway/class-wc-gateway-waffo-pancake.php`（`process_payment()`里构造`priceSnapshot`）

**Step 1: 确认`Waffo_Api_Client::create_checkout_session()`透传任意payload字段**

读取`includes/Api/Waffo_Api_Client.php`里`create_checkout_session(array $payload): array`的实现，确认它是直接把`$payload`原样传给`post()`（没有做白名单字段过滤）。如果确实是原样透传（大概率是，因为它是骨架实现里的薄封装），这一步不需要改代码，只需要在自我审查里明确记录"已确认此方法透传任意字段，加`priceSnapshot`无需修改这个类"。如果发现有字段白名单/过滤逻辑，需要先在这里补充讨论，不要直接假设。

**Step 2: 修改`process_payment()`，构造并传入`priceSnapshot`**

在`create_checkout_session()`调用的payload里增加`priceSnapshot`字段：

```php
            $session = $client->create_checkout_session([
                'productId'               => $this->resolve_waffo_product_id($order),
                'currency'                => $order->get_currency(),
                'orderMerchantExternalId' => (string) $order_id,
                'buyerEmail'              => $order->get_billing_email(),
                'successUrl'              => $this->get_return_url($order),
                'priceSnapshot'           => [
                    'amount'       => $this->build_price_snapshot_amount($order),
                    'taxIncluded'  => true,
                    'taxCategory'  => $this->get_option('waffo_tax_category', 'digital_goods'),
                ],
            ]);
```

新增private方法：

```php
    private function build_price_snapshot_amount(\WC_Order $order): string
    {
        $currency = $order->get_currency();
        $divisor = \WaffoPancake\Money\Waffo_Money::minor_unit_divisor($currency);
        $minor_amount = (int) round((float) $order->get_total() * $divisor);

        return \WaffoPancake\Money\Waffo_Money::to_display_string($minor_amount, $currency);
    }
```

**关于`taxIncluded`和`taxCategory`的说明（待确认项，先用保守默认值）**：
- `taxIncluded => true`：假设WooCommerce订单总额（`get_total()`）已经是含税价（这是WooCommerce的常见配置方式，取决于商户后台"Prices entered with tax"设置），这个假设**未经业务方最终确认**，如果商户的WC是配置成"不含税价格"，这里会导致税额计算错误（多收或少收税）。这是一个需要标注的跟进项，不是本任务能在没有更多商户税务配置信息的情况下彻底解决的。
- `taxCategory`：用了一个新的可配置项`waffo_tax_category`（需要在`init_form_fields()`里补充这个后台设置字段，默认值`digital_goods`，因为当前产品定位是虚拟商品），商户可以在后台配置自己的税务分类。这个字段的合法值需要参照Waffo Dashboard里税务分类的实际选项（本次调研没有获取到完整的合法值列表），先用文本输入框，不做下拉枚举校验。

**Step 3: 在`init_form_fields()`补充`waffo_tax_category`设置字段**

```php
            'waffo_tax_category' => [
                'title'       => 'Waffo Tax Category',
                'type'        => 'text',
                'description' => 'Tax category for pricing (e.g. "digital_goods", "saas"). See your Waffo Dashboard for valid values.',
                'default'     => 'digital_goods',
            ],
```

**Step 4: 语法验证**

Run: `php -l includes/Gateway/class-wc-gateway-waffo-pancake.php`
Expected: `No syntax errors detected`

**Step 5: 全量测试确认无回归**

Run: `composer test`

**Step 6: Commit**

```bash
git add includes/Gateway/class-wc-gateway-waffo-pancake.php
git commit -m "feat: process_payment支持通过priceSnapshot动态覆盖交易金额"
```

**本任务范围外、需要记录到Task 8清单的跟进项**：
- `taxIncluded => true`是否符合商户实际WC税务配置（含税价 vs 不含税价），需要业务方确认，当前是保守默认假设，不是已验证的正确行为
- `taxCategory`的合法值范围未知（本次调研未获取完整列表），当前用自由文本输入，如果商户填错值，Waffo API大概率会返回400错误，但错误提示对商户是否友好未经真实环境验证
- 如果商户的商品本身在Waffo后台已经配置了准确的价格和税务规则，本任务的改动会让**所有订单**都走`priceSnapshot`覆盖路径（即使金额和商品原价完全相同也会覆盖），这在功能上无害（覆盖成一样的值等于没覆盖），但意味着"控制这次交易最终什么价格生效"这件事完全转移到了WooCommerce订单总额上，商户后续如果只改Waffo后台商品价格而不同步注意WC商品价格，可能会困惑"为什么改了Waffo价格没生效"——这是一个值得在插件文档里说明的行为差异，非代码缺陷。

---

### Task 7: `process_refund()` 真实实现

**Files:**
- Modify: `includes/Gateway/class-wc-gateway-waffo-pancake.php`

**Step 1: 替换`process_refund()`实现**

```php
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

        try {
            $client = $this->build_api_client();

            $display_amount = \WaffoPancake\Money\Waffo_Money::to_display_string(
                (int) round($amount * \WaffoPancake\Money\Waffo_Money::minor_unit_divisor($order->get_currency())),
                $order->get_currency()
            );

            $ticket = $client->create_refund_ticket($payment_id, $display_amount, $order->get_currency(), $reason ?: 'Refund requested via WooCommerce');

            $order->add_order_note(sprintf(
                'Waffo refund ticket %s submitted (status: %s). Refund will complete once Waffo reviews and approves the request — this is not instant.',
                $ticket['ticketId'],
                $ticket['status']
            ));

            return true;
        } catch (\WaffoPancake\Api\Waffo_Api_Exception $e) {
            return new \WP_Error('waffo_refund_failed', 'Refund request failed: ' . $e->getMessage());
        }
    }
```

**Step 2: 语法验证**

Run: `php -l includes/Gateway/class-wc-gateway-waffo-pancake.php`

**Step 3: 全量测试确认无回归**

Run: `composer test`

**Step 4: Commit**

```bash
git add includes/Gateway/class-wc-gateway-waffo-pancake.php
git commit -m "feat: process_refund真实接入refund-ticket创建接口"
```

**重要说明（务必在文案/UI上体现，不要误导商户）**：本次真实API调研**没有找到**退款是否需要platform admin人工审核的最新确认信息（之前的调研与业务方的说法存在冲突，见设计文档第10节第4条，本次真实API调研聚焦在接口本身如何调用，没有专门去核实审核流程这件事）。`add_order_note`里的文案先按"非即时到账"的保守假设写（"not instant"），如果后续确认商户侧提交即final、无需审核，需要回来调整这段文案和商户预期管理方式。**这条本身就是一个未清偿的跟进项，不要认为Task 7完成后这件事就解决了。**

---

### Task 8: WP-Cron 兜底轮询

**Files:**
- Create: `includes/Cron/Waffo_Order_Reconciler.php`
- Test: `tests/Unit/Cron/Waffo_Order_Reconciler_Test.php`

**Step 1: 写失败的测试**

```php
<?php
namespace WaffoPancake\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Cron\Waffo_Order_Reconciler;
use WaffoPancake\Api\Waffo_Api_Client;

class Waffo_Order_Reconciler_Test extends TestCase
{
    public function test_reconcile_marks_order_processing_when_waffo_reports_completed(): void
    {
        $client = \Mockery::mock(Waffo_Api_Client::class);
        $client->shouldReceive('query_order_status')->with('cs_1')->andReturn(['status' => 'completed']);

        $updated = [];
        $reconciler = new Waffo_Order_Reconciler($client, function (string $session_id, string $status) use (&$updated) {
            $updated[] = [$session_id, $status];
        });

        $reconciler->reconcile_one('cs_1');

        $this->assertSame([['cs_1', 'completed']], $updated);
    }

    public function test_reconcile_does_nothing_when_status_unchanged(): void
    {
        $client = \Mockery::mock(Waffo_Api_Client::class);
        $client->shouldReceive('query_order_status')->with('cs_1')->andReturn(['status' => 'pending']);

        $updated = [];
        $reconciler = new Waffo_Order_Reconciler($client, function (string $session_id, string $status) use (&$updated) {
            $updated[] = [$session_id, $status];
        });

        $reconciler->reconcile_one('cs_1');

        $this->assertEmpty($updated, 'pending状态不应触发回调，只有明确的终态才应该更新');
    }

    public function test_reconcile_swallows_api_exception_for_single_order(): void
    {
        $client = \Mockery::mock(Waffo_Api_Client::class);
        $client->shouldReceive('query_order_status')->andThrow(new \WaffoPancake\Api\Waffo_Api_Exception('timeout'));

        $updated = [];
        $reconciler = new Waffo_Order_Reconciler($client, function () use (&$updated) {
            $updated[] = true;
        });

        // 不应该抛出异常中断整批轮询——一个订单查询失败不该影响其他订单
        $reconciler->reconcile_one('cs_1');

        $this->assertEmpty($updated);
    }
}
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Waffo_Order_Reconciler_Test`
Expected: FAIL，class not found

**Step 3: 写实现**

```php
<?php
namespace WaffoPancake\Cron;

use WaffoPancake\Api\Waffo_Api_Client;
use WaffoPancake\Api\Waffo_Api_Exception;

class Waffo_Order_Reconciler
{
    // 只有这两个终态才驱动状态更新；pending/expired等中间态留给下一轮轮询或人工介入
    private const TERMINAL_STATUSES = ['completed', 'failed'];

    private Waffo_Api_Client $client;
    /** @var callable */
    private $on_status_resolved;

    public function __construct(Waffo_Api_Client $client, callable $on_status_resolved)
    {
        $this->client = $client;
        $this->on_status_resolved = $on_status_resolved;
    }

    public function reconcile_one(string $checkout_session_id): void
    {
        try {
            $result = $this->client->query_order_status($checkout_session_id);
        } catch (Waffo_Api_Exception $e) {
            // 单个订单查询失败不应中断整批轮询，留给下一次Cron周期重试
            return;
        }

        $status = $result['status'] ?? null;
        if ($status !== null && in_array($status, self::TERMINAL_STATUSES, true)) {
            ($this->on_status_resolved)($checkout_session_id, $status);
        }
    }
}
```

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter Waffo_Order_Reconciler_Test`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add includes/Cron/ tests/Unit/Cron/
git commit -m "feat: 实现WP-Cron兜底轮询的核心对账逻辑"
```

**范围说明**：这个任务只实现"给定一个session_id，查询并判断是否需要更新"的核心逻辑单元，不包含真正注册`wp_schedule_event`定时任务、查询"哪些订单需要被轮询"（比如WC_Order_Query筛选on-hold超过2小时的订单）这部分胶水代码——那部分依赖`WC_Order_Query`，同Task 6/7一样只能做`php -l`检查，如果需要在本计划范围内一并完成，请在开始本任务前确认是否要扩展范围。

---

### Task 9: 插件入口接入webhook端点与Cron

**Files:**
- Modify: `waffo-pancake-woocommerce.php`

**Step 1: 在`plugins_loaded`回调里追加webhook控制器注册**

在现有的`add_filter('woocommerce_payment_gateways', ...)`之后追加：

```php
    require_once WAFFO_PANCAKE_WC_PLUGIN_DIR . 'includes/Webhook/Waffo_Webhook_Controller.php';

    // TODO: on_event回调目前是占位，需要接入Order_Status_Mapper + WC_Order状态更新的完整链路，
    // 这部分需要WC_Order真实环境验证，无法在当前纯PHPUnit环境完成，留待人工在WooCommerce
    // 测试站点验证后补齐。
    $verifier = new \WaffoPancake\Signing\Waffo_Webhook_Verifier(
        \WaffoPancake\Signing\Waffo_Webhook_Public_Keys::for_mode(
            get_option('woocommerce_waffo_pancake_settings')['environment'] ?? 'test'
        )
    );
    $dedup = new \WaffoPancake\Dedup\Waffo_Event_Deduplicator(new \WaffoPancake\Dedup\WP_Transient_Event_Store());

    $webhook_controller = new \WaffoPancake\Webhook\Waffo_Webhook_Controller($verifier, $dedup, function (array $event) {
        $status = \WaffoPancake\Order\Order_Status_Mapper::for_event($event['eventType'] ?? '');
        if ($status === null) {
            return;
        }

        $order_id = $event['data']['orderMerchantExternalId'] ?? null;
        if (!$order_id) {
            return;
        }

        $order = wc_get_order((int) $order_id);
        if ($order) {
            $order->update_status($status, 'Updated via Waffo webhook: ' . ($event['eventType'] ?? 'unknown'));
        }
    });
    $webhook_controller->register_routes();
```

**Step 2: 语法验证**

Run: `php -l waffo-pancake-woocommerce.php`
Expected: `No syntax errors detected`

**Step 3: 全量测试确认无回归**

Run: `composer test`

**Step 4: Commit**

```bash
git add waffo-pancake-woocommerce.php
git commit -m "feat: 插件入口注册webhook端点，接通事件→订单状态更新链路"
```

---

### Task 10: 更新骨架计划的Task 8清单 — 标注哪些已清偿、哪些仍待办

**Files:**
- Modify: `docs/plans/2026-08-26-plugin-scaffold-implementation.md`

**Step 1: 逐条核对Task 8清单的10条，在每条后面标注状态**

- 第1条（商户认证方式确认）→ 已清偿，本计划Task 1-2已按真实认证方式实现
- 第2条（webhook公钥获取）→ 部分清偿：已确认是固定值+找到存放位置，但代码里仍是占位符，需人工替换真实PEM后才算完全清偿
- 第3条（退款审核机制）→ **仍未清偿**，本计划Task 7的文案是保守假设，需要业务方明确答复
- 第4条（完整测试计划）→ 仍待办，需要真实WooCommerce测试站点
- 第5-8条（openssl错误码区分、防重放窗口时长、Money校验、transient key长度）→ 仍待办，本计划未涉及
- 第9条（私钥字段类型）→ 本计划Task 5已处理，但注明了`password`类型本身仍有局限（值仍会出现在HTML源码里），不算100%解决，只是从"完全明文可见的textarea"降级为"更难被随手看到"
- 第10条（process_payment标准错误处理）→ 已清偿，本计划Task 6已实现

**Step 2: 新增本轮调研发现的新跟进项**

追加以下条目：
- "WooCommerce商品↔Waffo商品的映射方案已定案（2026-08-28）：商户自定义字段`_waffo_product_id`，Task 6.5已实现商品编辑页UI，参照Lemon Squeezy等MoR类插件的业界惯例。此项已清偿，不再是待办。"
- "`resolve_waffo_product_id()`未配置时会在结账时才报错，用户体验较差，正式上线前应该在商户后台设置页加一个『检测未配置Waffo商品』的诊断工具或强制校验（仍待办，Task 6.5只解决了"能填"，没解决"忘记填时如何提前提醒"）"
- "webhook端点的`permission_callback`当前是`__return_true`（因为认证靠签名验证，不是WordPress权限系统），需要在真实环境确认这不会被WordPress其他安全插件/防火墙规则误拦截"

**Step 3: Commit**

```bash
git add docs/plans/2026-08-26-plugin-scaffold-implementation.md
git commit -m "docs: 更新骨架计划Task 8清单的清偿状态，记录新发现的跟进项"
```

---

## 执行后检查

全部任务完成后运行完整测试套件：

Run: `composer test`
Expected: 除`Waffo_Webhook_Public_Keys_Test::test_keys_are_valid_pem_public_keys`外全部通过（该测试会持续失败直到人工填入真实PEM公钥，这是有意为之的提醒机制，不要为了让它变绿而伪造密钥或删除断言）。

## 已知的、本计划有意不覆盖的范围

- 真实WooCommerce/WordPress环境端到端联调（需要真实测试站点）
- WP-Cron定时任务的实际注册（`wp_schedule_event`）与"筛选哪些订单需要轮询"的`WC_Order_Query`逻辑
- 订阅（`WC Subscriptions`）相关的真实接入（骨架设计文档提到需要支持，但本计划聚焦一次性支付先跑通）
- 退款审核机制的最终确认与商户端UI文案定稿


---

## 执行记录与偏差（2026-09-20）

Task 8–10 执行完毕，以下几处与计划原稿不同，以代码为准：

- **API 域名**：计划与骨架里 test 模式请求 `https://api.test.waffo.ai`，该主机不存在。官方文档明确 API Key 认证 test/prod 共用 `https://api.waffo.ai`，环境由 Key 绑定。已改为常量 `Waffo_Settings::API_BASE_URL`，Environment 选项只影响 webhook 验签公钥。
- **对账查询入口**：计划用 `onetimeOrder(id)`，但 create-session 只返回 `sessionId/checkoutUrl/expiresAt`，插件拿不到 Waffo 订单 ID。改为 `find_onetime_order_by_external_id(storeId, ref)`，走 `onetimeOrders` + `orderMerchantExternalId` 过滤，并带回 `payments{id status}`。因此网关新增 **Store ID** 设置项。
- **一次性订单终态**：计划写 `completed/failed`，官方生命周期实际是 `pending/completed/canceled`，无 `failed`。`canceled` 会把等待付款的 WC 订单同步为 `cancelled`。
- **去重键**：改为 `eventType:eventId` 复合键。官方 eventId Mapping 与生产实测：`subscription.activated` 与 `subscription.canceled` 共用 eventId（订单 ID）。
- **`_waffo_payment_id` 写入**：`process_refund()` 依赖此 meta，但计划里没有任何地方写入。现在 webhook `order.completed` 与 Cron 对账都通过 `Waffo_Order_Sync::mark_paid()` 写入，并用 WC 标准的 `payment_complete(PAY_id)` 推进状态（而非 `update_status('processing')`），幂等。
- **Task 8 范围扩展**：计划把 `wp_schedule_event` 与订单筛选列为范围外，本轮一并实现为 `Waffo_Reconcile_Scheduler`（每 15 分钟、下单 10 分钟后至 3 天内、每轮 ≤50 单、随插件启停注册/注销），仅 `php -l` 验证。
- **公钥占位符**：已填入真实 PEM，`Waffo_Webhook_Public_Keys_Test` 断言翻转为"两把均可解析、2048 位、互不相同"。
