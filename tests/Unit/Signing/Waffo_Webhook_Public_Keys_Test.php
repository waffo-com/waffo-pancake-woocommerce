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

        $this->assertNotFalse($test_key);
        $this->assertNotFalse($live_key);

        // 两把都应是2048位RSA公钥；test/live必须不同，防止复制粘贴时贴成同一把
        $this->assertSame(2048, openssl_pkey_get_details($test_key)['bits']);
        $this->assertSame(2048, openssl_pkey_get_details($live_key)['bits']);
        $this->assertNotSame(
            Waffo_Webhook_Public_Keys::for_mode('test'),
            Waffo_Webhook_Public_Keys::for_mode('prod')
        );
    }

    public function test_is_configured_returns_true_for_both_modes(): void
    {
        $this->assertTrue(Waffo_Webhook_Public_Keys::is_configured('test'));
        $this->assertTrue(Waffo_Webhook_Public_Keys::is_configured('prod'));
    }
}
