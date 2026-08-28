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
