# Waffo Pancake WooCommerce 插件 — 骨架实现计划

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 搭建插件骨架：`WC_Payment_Gateway` 集成、RSA-SHA256 签名的 API 客户端、webhook 验签与去重、订单状态机、Cron 兜底轮询——全部用单元测试覆盖核心逻辑，不依赖真实 Waffo 环境即可验证正确性。

**Architecture:** 遵循 `docs/plans/2026-08-25-woocommerce-plugin-design.md` 中确定的组件划分。测试用 PHPUnit + [WP_Mock](https://github.com/10up/wp_mock)（轻量 mock WordPress 函数，不需要起完整 WP 环境），签名算法和 payload 解析等纯逻辑单独抽成不依赖 WordPress 的类，方便直接单元测试。

**Tech Stack:** PHP 8.1+，PHPUnit 10，WP_Mock，Composer。

**范围说明：** 本计划实现的是骨架和可独立验证的核心逻辑。真实环境联调（Task 8）依赖设计文档第 10 节的待确认事项（商户 API 认证方式、webhook 公钥获取渠道），在这些点确认前，Task 8 只能用推断值实现，需要在待确认信息到位后回来校正。

---

### Task 0: 项目初始化（Composer + PHPUnit + WP_Mock）

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap.php`

**Step 1: 写 composer.json**

> **执行记录（2026-08-26）：** 实际执行时发现 `phpunit/phpunit: ^10` 与 `10up/wp_mock: ^1.0`（该库所有已发布 1.x 版本均硬性要求 `phpunit/phpunit ^9.6`）存在不可满足的依赖冲突，`composer install` 会直接报错。已确认改为 `phpunit/phpunit: ^9.6`，与 wp_mock 兼容，且不影响后续任务（均不依赖 PHPUnit 10 专属特性）。下面的示例已更新为修正后的版本，**请勿再改回 `^10`**。

```json
{
    "name": "waffo/waffo-pancake-woocommerce",
    "description": "Waffo Pancake payment gateway for WooCommerce",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "10up/wp_mock": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "WaffoPancake\\": "includes/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "WaffoPancake\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit"
    }
}
```

**Step 2: 写 phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**Step 3: 写 tests/bootstrap.php**

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

WP_Mock::bootstrap();
```

**Step 4: 安装依赖**

Run: `cd /Users/xichen.wu/Projects/waffo-pancake-woocommerce/.worktrees/scaffold && composer install`
Expected: 依赖安装成功，生成 `vendor/` 和 `composer.lock`

**Step 5: 验证 PHPUnit 能跑（先建一个占位测试）**

Create: `tests/Unit/PlaceholderTest.php`
```php
<?php
namespace WaffoPancake\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PlaceholderTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->assertTrue(true);
    }
}
```

Run: `composer test`
Expected: PASS (1 test, 1 assertion)

**Step 6: Commit**

```bash
git add composer.json phpunit.xml.dist tests/bootstrap.php tests/Unit/PlaceholderTest.php .gitignore
git commit -m "chore: 初始化Composer/PHPUnit测试基础设施"
```

（记得先在 `.gitignore` 里加 `vendor/` 和 `composer.lock` 是否要提交按 PHP 插件惯例——WordPress 插件通常提交 `composer.lock` 但不提交 `vendor/`，在此步骤一并创建 `.gitignore` 加入 `vendor/`）

---

### Task 1: 签名工具类 `Waffo_Signer`（纯逻辑，不依赖 WordPress）

这是最容易独立验证正确性的部分：RSA-SHA256 签名的 canonical string 构造。设计文档第 7 节：`canonical = "METHOD\nPATH\nTIMESTAMP\nBASE64(SHA256(BODY))"`，签名 = `RSA-SHA256(canonical, privateKey)` → Base64。

**Files:**
- Create: `includes/Signing/Waffo_Signer.php`
- Test: `tests/Unit/Signing/Waffo_Signer_Test.php`

**Step 1: 写失败的测试**

先用 `openssl` 命令行生成一对测试用的 RSA 密钥放进测试 fixture（不是真实商户密钥，纯测试用）：

Run:
```bash
mkdir -p tests/fixtures
openssl genrsa -out tests/fixtures/test_private_key.pem 2048
openssl rsa -in tests/fixtures/test_private_key.pem -pubout -out tests/fixtures/test_public_key.pem
```
Expected: 生成两个 PEM 文件

```php
<?php
namespace WaffoPancake\Tests\Unit\Signing;

use PHPUnit\Framework\TestCase;
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
}
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Waffo_Signer_Test`
Expected: FAIL，`Class "WaffoPancake\Signing\Waffo_Signer" not found`

**Step 3: 写最小实现**

```php
<?php
namespace WaffoPancake\Signing;

class Waffo_Signer
{
    private string $private_key_pem;

    public function __construct(string $private_key_pem)
    {
        $this->private_key_pem = $private_key_pem;
    }

    public function sign(string $method, string $path, int $timestamp_ms, string $body): string
    {
        $path_without_query = strtok($path, '?');
        $body_hash = base64_encode(hash('sha256', $body, true));

        $canonical = strtoupper($method) . "\n" . $path_without_query . "\n" . $timestamp_ms . "\n" . $body_hash;

        $private_key = openssl_pkey_get_private($this->private_key_pem);
        if ($private_key === false) {
            throw new \RuntimeException('Invalid RSA private key');
        }

        openssl_sign($canonical, $signature, $private_key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }
}
```

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter Waffo_Signer_Test`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add includes/Signing/Waffo_Signer.php tests/Unit/Signing/Waffo_Signer_Test.php tests/fixtures/
git commit -m "feat: 实现RSA-SHA256请求签名工具类"
```

---

### Task 2: Webhook 验签工具类 `Waffo_Webhook_Verifier`

设计文档第 7 节：`X-Waffo-Signature: t=<timestamp>,v1=<signature>`，验证公式 `RSA-SHA256("${timestamp}.${raw_body}", platform_public_key)`。

**Files:**
- Create: `includes/Signing/Waffo_Webhook_Verifier.php`
- Test: `tests/Unit/Signing/Waffo_Webhook_Verifier_Test.php`

**Step 1: 写失败的测试**

```php
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
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Waffo_Webhook_Verifier_Test`
Expected: FAIL，class not found

**Step 3: 写最小实现**

```php
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
```

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter Waffo_Webhook_Verifier_Test`
Expected: PASS (4 tests)

**Step 5: Commit**

```bash
git add includes/Signing/Waffo_Webhook_Verifier.php tests/Unit/Signing/Waffo_Webhook_Verifier_Test.php
git commit -m "feat: 实现webhook签名验证工具类"
```

---

### Task 3: 金额换算工具类 `Waffo_Money`

设计文档第 6/9 节：JPY/KRW/VND 最小单位是 1，其余货币是 100（cents）。

**Files:**
- Create: `includes/Money/Waffo_Money.php`
- Test: `tests/Unit/Money/Waffo_Money_Test.php`

**Step 1: 写失败的测试**

```php
<?php
namespace WaffoPancake\Tests\Unit\Money;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Money\Waffo_Money;

class Waffo_Money_Test extends TestCase
{
    public function test_minor_unit_divisor_for_standard_currency(): void
    {
        $this->assertSame(100, Waffo_Money::minor_unit_divisor('USD'));
        $this->assertSame(100, Waffo_Money::minor_unit_divisor('EUR'));
    }

    public function test_minor_unit_divisor_for_zero_decimal_currency(): void
    {
        $this->assertSame(1, Waffo_Money::minor_unit_divisor('JPY'));
        $this->assertSame(1, Waffo_Money::minor_unit_divisor('KRW'));
        $this->assertSame(1, Waffo_Money::minor_unit_divisor('VND'));
    }

    public function test_minor_unit_divisor_is_case_insensitive(): void
    {
        $this->assertSame(1, Waffo_Money::minor_unit_divisor('jpy'));
    }

    public function test_to_display_string_standard_currency(): void
    {
        $this->assertSame('29.00', Waffo_Money::to_display_string(2900, 'USD'));
    }

    public function test_to_display_string_zero_decimal_currency(): void
    {
        $this->assertSame('2900', Waffo_Money::to_display_string(2900, 'JPY'));
    }
}
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Waffo_Money_Test`
Expected: FAIL，class not found

**Step 3: 写最小实现**

```php
<?php
namespace WaffoPancake\Money;

class Waffo_Money
{
    // 源码调研确认的零小数货币清单（非官方文档，见设计文档第10节，可能不完整）
    private const ZERO_DECIMAL_CURRENCIES = ['JPY', 'KRW', 'VND'];

    public static function minor_unit_divisor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100;
    }

    public static function to_display_string(int $minor_amount, string $currency): string
    {
        $divisor = self::minor_unit_divisor($currency);
        $value = $minor_amount / $divisor;

        return $divisor === 1 ? (string) $value : number_format($value, 2, '.', '');
    }
}
```

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter Waffo_Money_Test`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add includes/Money/Waffo_Money.php tests/Unit/Money/Waffo_Money_Test.php
git commit -m "feat: 实现货币最小单位换算工具类"
```

---

### Task 4: Webhook 去重存储接口 `Waffo_Event_Deduplicator`

设计文档第 7 节：按 `eventId` 去重。为了不在纯单元测试里依赖真实的 WordPress transient API，先定义一个存储接口，插件里用 WordPress transient 实现，测试里用内存实现。

**Files:**
- Create: `includes/Dedup/Event_Store_Interface.php`
- Create: `includes/Dedup/Waffo_Event_Deduplicator.php`
- Create: `includes/Dedup/In_Memory_Event_Store.php`（供测试和后续 WP transient 实现参考）
- Test: `tests/Unit/Dedup/Waffo_Event_Deduplicator_Test.php`

**Step 1: 写失败的测试**

```php
<?php
namespace WaffoPancake\Tests\Unit\Dedup;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Dedup\Waffo_Event_Deduplicator;
use WaffoPancake\Dedup\In_Memory_Event_Store;

class Waffo_Event_Deduplicator_Test extends TestCase
{
    public function test_first_time_event_is_not_duplicate(): void
    {
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $this->assertFalse($dedup->is_duplicate('PAY_1'));
    }

    public function test_marking_processed_makes_subsequent_check_duplicate(): void
    {
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $dedup->mark_processed('PAY_1');

        $this->assertTrue($dedup->is_duplicate('PAY_1'));
    }

    public function test_different_event_ids_are_independent(): void
    {
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $dedup->mark_processed('PAY_1');

        $this->assertFalse($dedup->is_duplicate('PAY_2'));
    }
}
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Waffo_Event_Deduplicator_Test`
Expected: FAIL，classes not found

**Step 3: 写最小实现**

```php
<?php
namespace WaffoPancake\Dedup;

interface Event_Store_Interface
{
    public function has(string $event_id): bool;
    public function put(string $event_id): void;
}
```

```php
<?php
namespace WaffoPancake\Dedup;

class In_Memory_Event_Store implements Event_Store_Interface
{
    private array $seen = [];

    public function has(string $event_id): bool
    {
        return isset($this->seen[$event_id]);
    }

    public function put(string $event_id): void
    {
        $this->seen[$event_id] = true;
    }
}
```

```php
<?php
namespace WaffoPancake\Dedup;

class Waffo_Event_Deduplicator
{
    private Event_Store_Interface $store;

    public function __construct(Event_Store_Interface $store)
    {
        $this->store = $store;
    }

    public function is_duplicate(string $event_id): bool
    {
        return $this->store->has($event_id);
    }

    public function mark_processed(string $event_id): void
    {
        $this->store->put($event_id);
    }
}
```

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter Waffo_Event_Deduplicator_Test`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add includes/Dedup/
git commit -m "feat: 实现webhook事件去重逻辑"
```

---

### Task 5: WordPress Transient 存储实现（连接去重逻辑到真实 WordPress）

**Files:**
- Create: `includes/Dedup/WP_Transient_Event_Store.php`
- Test: `tests/Unit/Dedup/WP_Transient_Event_Store_Test.php`

**Step 1: 写失败的测试（用 WP_Mock 模拟 transient 函数）**

```php
<?php
namespace WaffoPancake\Tests\Unit\Dedup;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Dedup\WP_Transient_Event_Store;

class WP_Transient_Event_Store_Test extends TestCase
{
    protected function setUp(): void
    {
        \WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        \WP_Mock::tearDown();
    }

    public function test_has_returns_false_when_no_transient(): void
    {
        \WP_Mock::userFunction('get_transient')
            ->with('waffo_evt_PAY_1')
            ->andReturn(false);

        $store = new WP_Transient_Event_Store();

        $this->assertFalse($store->has('PAY_1'));
    }

    public function test_has_returns_true_when_transient_exists(): void
    {
        \WP_Mock::userFunction('get_transient')
            ->with('waffo_evt_PAY_1')
            ->andReturn(true);

        $store = new WP_Transient_Event_Store();

        $this->assertTrue($store->has('PAY_1'));
    }

    public function test_put_sets_transient_with_expiry(): void
    {
        \WP_Mock::userFunction('set_transient')
            ->with('waffo_evt_PAY_1', true, \Mockery::type('int'))
            ->once();

        $store = new WP_Transient_Event_Store();
        $store->put('PAY_1');

        $this->assertTrue(true); // Mockery expectation 是真正的断言
    }
}
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter WP_Transient_Event_Store_Test`
Expected: FAIL，class not found

**Step 3: 写最小实现**

```php
<?php
namespace WaffoPancake\Dedup;

class WP_Transient_Event_Store implements Event_Store_Interface
{
    // 7天过期：远超Waffo webhook可能的重试窗口（重试策略本身待确认，见设计文档第10节）
    private const TTL_SECONDS = 7 * DAY_IN_SECONDS;

    public function has(string $event_id): bool
    {
        return get_transient($this->key($event_id)) !== false;
    }

    public function put(string $event_id): void
    {
        set_transient($this->key($event_id), true, self::TTL_SECONDS);
    }

    private function key(string $event_id): string
    {
        return 'waffo_evt_' . $event_id;
    }
}
```

注意：`DAY_IN_SECONDS` 是 WordPress 内置常量，测试环境里 WP_Mock 不会自动定义它，需要在 `tests/bootstrap.php` 里补一行 `define('DAY_IN_SECONDS', 86400);`（如果 WP_Mock 版本没有自带的话，先跑测试看是否报错再决定是否要加）。

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter WP_Transient_Event_Store_Test`
Expected: PASS (3 tests)。如果因为 `DAY_IN_SECONDS` 未定义报错，回到 `tests/bootstrap.php` 补充 `define()`，重新运行至通过。

**Step 5: Commit**

```bash
git add includes/Dedup/WP_Transient_Event_Store.php tests/Unit/Dedup/WP_Transient_Event_Store_Test.php tests/bootstrap.php
git commit -m "feat: 用WordPress transient实现事件去重存储"
```

---

### Task 6: Webhook Payload 解析与订单状态映射器 `Order_Status_Mapper`

设计文档第 6 节的状态映射表，先把"给定一个 webhook 事件类型，应该把 WooCommerce 订单状态改成什么"这个纯逻辑抽出来单测，不涉及真的去改 WC 订单（那部分要用到 `WC_Order`，属于集成逻辑，本计划不含，留给后续对接真实 WooCommerce 环境时的任务）。

**Files:**
- Create: `includes/Order/Order_Status_Mapper.php`
- Test: `tests/Unit/Order/Order_Status_Mapper_Test.php`

**Step 1: 写失败的测试**

```php
<?php
namespace WaffoPancake\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Order\Order_Status_Mapper;

class Order_Status_Mapper_Test extends TestCase
{
    public function test_order_completed_maps_to_processing(): void
    {
        $this->assertSame('processing', Order_Status_Mapper::for_event('order.completed'));
    }

    public function test_subscription_activated_maps_to_active(): void
    {
        $this->assertSame('active', Order_Status_Mapper::for_event('subscription.activated'));
    }

    public function test_subscription_canceled_maps_to_cancelled(): void
    {
        $this->assertSame('cancelled', Order_Status_Mapper::for_event('subscription.canceled'));
    }

    public function test_unknown_event_returns_null(): void
    {
        $this->assertNull(Order_Status_Mapper::for_event('some.unknown.event'));
    }
}
```

**Step 2: 运行测试确认失败**

Run: `composer test -- --filter Order_Status_Mapper_Test`
Expected: FAIL，class not found

**Step 3: 写最小实现**

```php
<?php
namespace WaffoPancake\Order;

class Order_Status_Mapper
{
    // 映射表来自设计文档第6节；订阅侧状态名是WC Subscriptions插件的标准状态slug
    private const EVENT_TO_STATUS = [
        'order.completed'                    => 'processing',
        'subscription.activated'             => 'active',
        'subscription.payment_succeeded'     => 'active',
        'subscription.canceling'             => 'pending-cancel',
        'subscription.uncanceled'            => 'active',
        'subscription.canceled'              => 'cancelled',
        'subscription.past_due'              => 'on-hold',
    ];

    public static function for_event(string $event_type): ?string
    {
        return self::EVENT_TO_STATUS[$event_type] ?? null;
    }
}
```

**Step 4: 运行测试确认通过**

Run: `composer test -- --filter Order_Status_Mapper_Test`
Expected: PASS (4 tests)

**Step 5: Commit**

```bash
git add includes/Order/Order_Status_Mapper.php tests/Unit/Order/Order_Status_Mapper_Test.php
git commit -m "feat: 实现webhook事件到订单状态的映射逻辑"
```

---

### Task 7: 插件入口文件与 WC_Payment_Gateway 骨架（无测试，标准 WordPress 插件样板）

这部分是 WordPress 插件的标准入口和网关注册代码，依赖 `WC_Payment_Gateway` 基类（只在真实 WooCommerce 环境里存在），不适合单元测试覆盖，遵循 WordPress 插件惯例直接编写并留 TODO 标记后续任务要接的点。

**Files:**
- Create: `waffo-pancake-woocommerce.php`
- Create: `includes/Gateway/class-wc-gateway-waffo-pancake.php`

**Step 1: 写插件入口文件**

```php
<?php
/**
 * Plugin Name: Waffo Pancake for WooCommerce
 * Description: Accept payments via Waffo Pancake hosted checkout.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WAFFO_PANCAKE_WC_PLUGIN_FILE', __FILE__);
define('WAFFO_PANCAKE_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once WAFFO_PANCAKE_WC_PLUGIN_DIR . 'vendor/autoload.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>Waffo Pancake for WooCommerce requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    require_once WAFFO_PANCAKE_WC_PLUGIN_DIR . 'includes/Gateway/class-wc-gateway-waffo-pancake.php';

    add_filter('woocommerce_payment_gateways', function (array $gateways): array {
        $gateways[] = \WaffoPancake\Gateway\WC_Gateway_Waffo_Pancake::class;
        return $gateways;
    });
});
```

**Step 2: 写网关骨架类**

```php
<?php
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

        // TODO(Task 8+): 注册webhook REST端点、Cron兜底任务
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
                'title'   => 'Environment',
                'type'    => 'select',
                'options' => ['test' => 'Test', 'prod' => 'Production'],
                'default' => 'test',
            ],
            'merchant_id' => [
                'title'       => 'Merchant ID',
                'type'        => 'text',
                'description' => 'Your Waffo Merchant ID (MER_xxx), from Waffo Dashboard API key settings.',
            ],
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
        // TODO(Task 8+): 调用 create-checkout-session，写入 orderMerchantExternalId=$order_id，重定向到checkoutUrl
        throw new \Exception('Not yet implemented — depends on confirming merchant checkout-session API details (see design doc §10.1-2)');
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        // TODO(Task 8+): 对接退款接口；具体交互模式依赖设计文档§10.4待确认的审核机制
        return new \WP_Error('not_implemented', 'Refund handling pending confirmation of Waffo refund review process (see design doc §10.4)');
    }
}
```

**Step 3: 手动验证语法正确（无 WordPress 环境，先用 php -l 做基本 lint）**

Run: `php -l waffo-pancake-woocommerce.php && php -l includes/Gateway/class-wc-gateway-waffo-pancake.php`
Expected: `No syntax errors detected` ×2

**Step 4: Commit**

```bash
git add waffo-pancake-woocommerce.php includes/Gateway/
git commit -m "feat: 添加插件入口与WC_Payment_Gateway骨架"
```

---

### Task 8: 真实环境联调（阻塞中，依赖待确认信息）

**不在本次骨架实现范围内执行**，仅记录清楚后续步骤，等设计文档第 10 节的待确认事项拿到答案后再排期：

1. 确认商户模式 `create-checkout-session` / `issue-session-token` 完整字段和认证头 → 完成 `Waffo_Api_Client::create_checkout_session()`
2. 确认 webhook 平台公钥获取方式 → 把 `Waffo_Webhook_Verifier` 接入真实的 webhook REST 端点（`includes/Webhook/Waffo_Webhook_Controller.php`，注册 `register_rest_route`）
3. 确认退款审核机制的真实行为 → 定稿 `process_refund()` 的用户提示文案和状态流转
4. 用 test 环境密钥对，走通设计文档第 9 节的完整测试计划
5. Webhook端点实现时需区分 `openssl_verify` 返回 `-1`（内部错误，如公钥损坏）与 `0`（验证失败/真实伪造）并分别记录日志，避免运维无法区分攻击与配置错误
6. 确认防重放时间窗口的最终时长（当前5分钟为占位值），并评估是否需要区分"时间戳过老"与"时间戳来自未来"两种拒绝原因分别记日志，便于排查NTP时钟漂移类故障
7. Waffo_Money接入真实订单流程前，需决策：是否对负数金额/非法货币码做输入校验并抛异常；是否需要为PHP_INT_MAX边界值和零小数货币分支补充防御性格式化

---

## 执行后检查

全部任务完成后，运行完整测试套件确认没有回归：

Run: `composer test`
Expected: 所有测试通过（Task 0-6 累计约 22 个测试）
