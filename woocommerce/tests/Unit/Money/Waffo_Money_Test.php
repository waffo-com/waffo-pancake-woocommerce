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
