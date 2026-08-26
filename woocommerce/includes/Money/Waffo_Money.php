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
