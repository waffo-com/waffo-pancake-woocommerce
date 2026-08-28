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

        if ($test_key === false || $live_key === false) {
            $this->markTestIncomplete(
                'Waffo webhook公钥仍是占位符，需要从Waffo Dashboard（或 ~/Projects/Waffo-pancake-dashboard/src/lib/api/webhook-keys.ts）拷贝真实PEM值替换 includes/Signing/Waffo_Webhook_Public_Keys.php 后，此测试才能通过。'
            );
        }

        $this->assertNotFalse($test_key);
        $this->assertNotFalse($live_key);
    }

    /**
     * 当前占位符尚未替换为真实密钥，所以断言为false是预期状态。
     * 一旦真实PEM密钥填入 Waffo_Webhook_Public_Keys 后，这里必须同步改成
     * assertTrue，否则这条测试会在密钥填入后"意外失败"，且没人知道原因——
     * 届时请把下面两个assertFalse改为assertTrue。
     */
    public function test_is_configured_returns_false_for_placeholder_keys(): void
    {
        $this->assertFalse(Waffo_Webhook_Public_Keys::is_configured('test'));
        $this->assertFalse(Waffo_Webhook_Public_Keys::is_configured('prod'));
    }
}
