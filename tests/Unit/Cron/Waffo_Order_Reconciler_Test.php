<?php
namespace WaffoPancake\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Api\Waffo_Api_Client;
use WaffoPancake\Api\Waffo_Api_Exception;
use WaffoPancake\Cron\Waffo_Order_Reconciler;
use WaffoPancake\Order\Waffo_Order_Sync;

class Waffo_Order_Reconciler_Test extends TestCase
{
    private array $logs = [];

    protected function tearDown(): void
    {
        \Mockery::close();
    }

    private function make_reconciler($client, $sync): Waffo_Order_Reconciler
    {
        return new Waffo_Order_Reconciler($client, 'STO_1', $sync, function (string $m) {
            $this->logs[] = $m;
        });
    }

    public function test_completed_order_marks_wc_order_paid_with_succeeded_payment_id(): void
    {
        $client = \Mockery::mock(Waffo_Api_Client::class);
        $client->shouldReceive('find_onetime_order_by_external_id')
            ->once()->with('STO_1', '42')
            ->andReturn([
                'id' => 'ORD_1', 'status' => 'completed',
                'payments' => [
                    ['id' => 'PAY_declined', 'status' => 'failed'],
                    ['id' => 'PAY_ok', 'status' => 'succeeded'],
                ],
            ]);

        $sync = \Mockery::mock(Waffo_Order_Sync::class);
        $sync->shouldReceive('mark_paid')->once()->with('42', 'PAY_ok', \Mockery::pattern('/reconcil/'))->andReturn(true);

        $this->assertSame('completed', $this->make_reconciler($client, $sync)->reconcile_one('42'));
    }

    public function test_canceled_order_marks_wc_order_canceled(): void
    {
        $client = \Mockery::mock(Waffo_Api_Client::class);
        $client->shouldReceive('find_onetime_order_by_external_id')
            ->andReturn(['id' => 'ORD_1', 'status' => 'canceled', 'payments' => []]);

        $sync = \Mockery::mock(Waffo_Order_Sync::class);
        $sync->shouldReceive('mark_canceled')->once()->with('42', \Mockery::type('string'))->andReturn(true);

        $this->assertSame('canceled', $this->make_reconciler($client, $sync)->reconcile_one('42'));
    }

    public function test_pending_order_does_nothing(): void
    {
        $client = \Mockery::mock(Waffo_Api_Client::class);
        $client->shouldReceive('find_onetime_order_by_external_id')
            ->andReturn(['id' => 'ORD_1', 'status' => 'pending', 'payments' => []]);

        $sync = \Mockery::mock(Waffo_Order_Sync::class);
        $sync->shouldNotReceive('mark_paid');
        $sync->shouldNotReceive('mark_canceled');

        // pending是中间态：买家被拒后可重试，留给下一轮
        $this->assertSame('pending', $this->make_reconciler($client, $sync)->reconcile_one('42'));
    }

    public function test_unknown_order_logs_and_does_nothing(): void
    {
        $client = \Mockery::mock(Waffo_Api_Client::class);
        $client->shouldReceive('find_onetime_order_by_external_id')->andReturn(null);

        $sync = \Mockery::mock(Waffo_Order_Sync::class);
        $sync->shouldNotReceive('mark_paid');
        $sync->shouldNotReceive('mark_canceled');

        $this->assertNull($this->make_reconciler($client, $sync)->reconcile_one('42'));
        $this->assertCount(1, $this->logs);
    }

    public function test_api_exception_is_swallowed_and_logged(): void
    {
        $client = \Mockery::mock(Waffo_Api_Client::class);
        $client->shouldReceive('find_onetime_order_by_external_id')->andThrow(new Waffo_Api_Exception('timeout'));

        $sync = \Mockery::mock(Waffo_Order_Sync::class);
        $sync->shouldNotReceive('mark_paid');

        // 单个订单查询失败不应中断整批轮询，留给下一次Cron周期重试
        $this->assertNull($this->make_reconciler($client, $sync)->reconcile_one('42'));
        $this->assertStringContainsString('timeout', $this->logs[0]);
    }

    public function test_reconcile_many_continues_after_failure(): void
    {
        $client = \Mockery::mock(Waffo_Api_Client::class);
        $client->shouldReceive('find_onetime_order_by_external_id')->with('STO_1', '1')->andThrow(new Waffo_Api_Exception('boom'));
        $client->shouldReceive('find_onetime_order_by_external_id')->with('STO_1', '2')
            ->andReturn(['id' => 'ORD_2', 'status' => 'completed', 'payments' => [['id' => 'PAY_2', 'status' => 'succeeded']]]);

        $sync = \Mockery::mock(Waffo_Order_Sync::class);
        $sync->shouldReceive('mark_paid')->once()->with('2', 'PAY_2', \Mockery::any())->andReturn(true);

        $result = $this->make_reconciler($client, $sync)->reconcile_many(['1', '2']);

        $this->assertSame(['1' => null, '2' => 'completed'], $result);
    }
}
