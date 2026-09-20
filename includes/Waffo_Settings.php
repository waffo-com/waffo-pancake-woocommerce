<?php
namespace WaffoPancake;

use WaffoPancake\Api\Waffo_Api_Client;
use WaffoPancake\Signing\Waffo_Signer;

/**
 * 读取网关设置的轻量入口，供网关类之外的组件（webhook控制器、Cron调度器）使用，
 * 避免它们为了拿 merchant_id / private_key 而去实例化整个 WC_Payment_Gateway。
 *
 * 设置存储在 WooCommerce 的标准 option：woocommerce_{gateway_id}_settings。
 */
class Waffo_Settings
{
    public const GATEWAY_ID = 'waffo_pancake';
    public const OPTION_NAME = 'woocommerce_waffo_pancake_settings';
    public const API_BASE_URL = 'https://api.waffo.ai';

    public static function get(string $key, string $default = ''): string
    {
        $settings = get_option(self::OPTION_NAME, []);
        if (!is_array($settings)) {
            return $default;
        }
        $value = $settings[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public static function environment(): string
    {
        return self::get('environment', 'test') === 'prod' ? 'prod' : 'test';
    }

    public static function store_id(): string
    {
        return trim(self::get('store_id', ''));
    }

    public static function is_api_configured(): bool
    {
        return self::get('merchant_id', '') !== '' && self::get('private_key', '') !== '';
    }

    /**
     * test/prod共用同一个API域名：API Key在Dashboard创建时就绑定了环境，服务端按验签成功的
     * 那把Key决定环境（官方文档 api-reference/authentication）。Environment选项只影响webhook验签公钥。
     */
    public static function make_api_client(): Waffo_Api_Client
    {
        $signer = new Waffo_Signer(self::get('private_key', ''));
        return new Waffo_Api_Client($signer, self::get('merchant_id', ''), self::API_BASE_URL);
    }

    /**
     * 统一的日志出口：优先走 WooCommerce 日志（后台 WooCommerce → Status → Logs 可见），无WC时退回error_log。
     */
    public static function log(string $level, string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, '[waffo_pancake] ' . $message, ['source' => self::GATEWAY_ID]);
            return;
        }
        error_log('[waffo_pancake] ' . $message);
    }
}
