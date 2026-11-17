<?php
namespace WaffoPancake\Signing;

/**
 * 内置的Waffo平台webhook验签公钥（test/prod两套，全部商户共用，不需要商户自行配置）。
 *
 * 密钥来源：Waffo Dashboard → Settings → Webhooks 页面展示的 Webhook Public Key，
 * 与官方 SDK @waffo/pancake-ts 内置的一致。平台换钥时需同步更新这里并发版。
 * is_configured() 保留作为防御性检查，供后台诊断/验签前确认PEM可被openssl解析。
 */
class Waffo_Webhook_Public_Keys
{
    // 这两把是Waffo平台侧固定公钥（test/live各一把），全部商户共用，不需要商户自行配置。
    private const TEST_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxnmRY6yMMA3lVqmAU6ZG
b1sjL/+r/z6E+ZjkXaDAKiqOhk9rpazni0bNsGXwmftTPk9jy2wn+j6JHODD/WH/
SCnSfvKkLIjy4Hk7BuCgB174C0ydan7J+KgXLkOwgCAxxB68t2tezldwo74ZpXgn
F49opzMvQ9prEwIAWOE+kV9iK6gx/AckSMtHIHpUesoPDkldpmFHlB2qpf1vsFTZ
5kD6DmGl+2GIVK01aChy2lk8pLv0yUMu18v44sLkO5M44TkGPJD9qG09wrvVG2wp
OTVCn1n5pP8P+HRLcgzbUB3OlZVfdFurn6EZwtyL4ZD9kdkQ4EZE/9inKcp3c1h4
xwIDAQAB
-----END PUBLIC KEY-----
PEM;

    private const PROD_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAz+xApdTIb4ua+DgZKQ54
iBsD82ybyhGCLRETONW4Jgbb3A8DUM1LqBk6r/CmTOCHqLalTQHNigvP3R5zkDNX
iRJz6gA4MJ/+8K0+mnEE2RISQzN+Qu65TNd6svb+INm/kMaftY4uIXr6y6kchtTJ
dwnQhcKdAL2v7h7IFnkVelQsKxDdb2PqX8xX/qwd01iXvMcpCCaXovUwZsxH2QN5
ZKBTseJivbhUeyJCco4fdUyxOMHe2ybCVhyvim2uxAl1nkvL5L8RCWMCAV55LLo0
9OhmLahz/DYNu13YLVP6dvIT09ZFBYU6Owj1NxdinTynlJCFS9VYwBgmftosSE1U
dwIDAQAB
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
     * 公钥能否被openssl解析，避免在验签时才让底层报出隐晦错误。
     */
    public static function is_configured(string $mode): bool
    {
        return openssl_pkey_get_public(self::for_mode($mode)) !== false;
    }
}
