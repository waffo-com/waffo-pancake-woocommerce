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
