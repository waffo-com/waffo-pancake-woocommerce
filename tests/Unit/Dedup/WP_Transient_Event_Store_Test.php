<?php
namespace WaffoPancake\Tests\Unit\Dedup;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Dedup\WP_Transient_Event_Store;

class WP_Transient_Event_Store_Test extends TestCase
{
    protected function setUp(): void
    {
        \WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        \WP_Mock::tearDown();
    }

    public function test_has_returns_false_when_no_transient(): void
    {
        \WP_Mock::userFunction('get_transient')
            ->with('waffo_evt_PAY_1')
            ->andReturn(false);

        $store = new WP_Transient_Event_Store();

        $this->assertFalse($store->has('PAY_1'));
    }

    public function test_has_returns_true_when_transient_exists(): void
    {
        \WP_Mock::userFunction('get_transient')
            ->with('waffo_evt_PAY_1')
            ->andReturn(true);

        $store = new WP_Transient_Event_Store();

        $this->assertTrue($store->has('PAY_1'));
    }

    public function test_put_sets_transient_with_expiry(): void
    {
        \WP_Mock::userFunction('set_transient')
            ->with('waffo_evt_PAY_1', true, \Mockery::type('int'))
            ->once();

        $store = new WP_Transient_Event_Store();
        $store->put('PAY_1');

        $this->assertTrue(true); // Mockery expectation 是真正的断言
    }
}
