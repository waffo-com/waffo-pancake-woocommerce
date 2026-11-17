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

    /**
     * 把WC订单总额（$order->get_total()，永远是major-unit的十进制字符串，例如USD的
     * "29.00"或JPY的"2900"——WooCommerce按货币的小数位设置格式化，不是minor-unit）
     * 转换成priceSnapshot需要的显示格式字符串。
     *
     * 用于Waffo API的priceSnapshot.amount字段：金额必须来自服务端计算好的订单总额，
     * 调用方（Gateway）不应对$total做任何自行拼接或校验，只负责传递原始值。
     */
    public static function from_order_total(string $total, string $currency): string
    {
        $divisor = self::minor_unit_divisor($currency);
        $minor_amount = (int) round((float) $total * $divisor);

        return self::to_display_string($minor_amount, $currency);
    }
}
