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

    public function test_to_display_string_rounds_correctly(): void
    {
        // 经典浮点陷阱值：301/100在二进制浮点下不精确表示，回归验证number_format能正确舍入到2位小数
        $this->assertSame('3.01', Waffo_Money::to_display_string(301, 'USD'));
    }

    public function test_to_display_string_handles_negative_amount(): void
    {
        // 锁定当前实际行为（负数金额未做输入校验，是否应该拒绝是后续设计决策，见Task 8跟进项）
        $this->assertSame('-29.00', Waffo_Money::to_display_string(-2900, 'USD'));
    }

    public function test_from_order_total_standard_two_decimal_amount(): void
    {
        $this->assertSame('29.00', Waffo_Money::from_order_total('29.00', 'USD'));
    }

    public function test_from_order_total_rounds_boundary_precision_value(): void
    {
        // "29.005" * 100 = 2900.5（浮点下不一定精确），round()按PHP默认的
        // 四舍五入（远离零方向）取整为2901分，对应显示值"29.01"。
        $this->assertSame('29.01', Waffo_Money::from_order_total('29.005', 'USD'));
    }

    public function test_from_order_total_zero_decimal_currency(): void
    {
        // JPY场景下，$order->get_total()本身就是WooCommerce按JPY小数位数(0位)
        // 格式化后的major-unit字符串（"2900"，而不是"29.00"），divisor=1，
        // 转换后原样输出"2900"。
        $this->assertSame('2900', Waffo_Money::from_order_total('2900', 'JPY'));
    }

    public function test_from_order_total_empty_string_input(): void
    {
        // 锁定当前行为：空字符串被(float)转型为0.0，不抛异常，输出"0.00"。
        // 是否应该在这里主动拒绝空输入是后续设计决策，不在本次改动范围内。
        $this->assertSame('0.00', Waffo_Money::from_order_total('', 'USD'));
    }

    public function test_from_order_total_non_numeric_string_input(): void
    {
        // 锁定当前行为：非数字字符串被(float)转型为0.0，不抛异常，输出"0.00"。
        $this->assertSame('0.00', Waffo_Money::from_order_total('abc', 'USD'));
    }
}
