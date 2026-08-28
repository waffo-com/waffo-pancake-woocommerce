<?php
namespace WaffoPancake\Signing;

/**
 * 内置的Waffo平台webhook验签公钥（test/prod两套，全部商户共用，不需要商户自行配置）。
 *
 * 注意：for_mode() 只负责返回对应环境的PEM文本，不保证其内容合法——
 * 在占位符尚未被真实值替换前，返回的字符串不是合法PEM。调用方在做实际
 * 验签前，应先用 is_configured() 或自行调用 openssl_pkey_get_public()
 * 做校验，不要假设 for_mode() 的返回值一定可用。
 */
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

    private const PROD_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
REPLACE_WITH_REAL_LIVE_PUBLIC_KEY_FROM_WAFFO_DASHBOARD
-----END PUBLIC KEY-----
PEM;

    public static function for_mode(string $mode): string
    {
        return match ($mode) {
            'test' => self::TEST_KEY,
            'prod' => self::PROD_KEY,
            default => throw new \InvalidArgumentException('Unknown Waffo environment mode: ' . $mode),
        };
    }

    /**
     * 供webhook控制器/后台设置页在真正验签前做防御性检查：判断对应mode的
     * 公钥是否已经是合法PEM（即占位符是否已被真实值替换），避免在验签时
     * 才让openssl底层报出隐晦错误。
     */
    public static function is_configured(string $mode): bool
    {
        return openssl_pkey_get_public(self::for_mode($mode)) !== false;
    }
}
