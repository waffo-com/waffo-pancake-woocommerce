<?php
namespace WaffoPancake\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Order\Waffo_Order_Sync;

/**
 * 模拟WC_Order的最小子集（鸭子类型），只实现Waffo_Order_Sync会调用的方法。
 */
class Fake_Order
{
    public string $status;
    public array $meta = [];
    public array $notes = [];
    public int $saves = 0;
    public ?string $completed_with_txn = null;

    public function __construct(string $status = 'on-hold')
    {
        $this->status = $status;
    }

    public function has_status($statuses): bool
    {
        return in_array($this->status, (array) $statuses, true);
    }

    public function get_meta(string $key)
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function add_order_note(string $note): void
    {
        $this->notes[] = $note;
    }

    public function payment_complete($transaction_id = ''): bool
    {
        $this->completed_with_txn = $transaction_id;
        $this->status = 'processing';
        return true;
    }

    public function update_status(string $status, string $note = ''): bool
    {
        $this->status = $status;
        if ($note !== '') {
            $this->notes[] = $note;
        }
        return true;
    }

    public function save(): void
    {
        $this->saves++;
    }
}

class Waffo_Order_Sync_Test extends TestCase
{
    /** @var array<string, Fake_Order> */
    private array $orders = [];
    private array $logs = [];

    private function make_sync(): Waffo_Order_Sync
    {
        return new Waffo_Order_Sync(
            fn (string $id) => $this->orders[$id] ?? null,
            function (string $message) {
                $this->logs[] = $message;
            }
        );
    }

    public function test_mark_paid_completes_payment_and_records_payment_id(): void
    {
        $this->orders['42'] = new Fake_Order('on-hold');

        $result = $this->make_sync()->mark_paid('42', 'PAY_1', 'webhook order.completed');

        $this->assertTrue($result);
        $this->assertSame('PAY_1', $this->orders['42']->completed_with_txn);
        $this->assertSame('PAY_1', $this->orders['42']->meta['_waffo_payment_id']);
        $this->assertSame('processing', $this->orders['42']->status);
        $this->assertSame(1, $this->orders['42']->saves);
        $this->assertStringContainsString('webhook order.completed', $this->orders['42']->notes[0]);
    }

    public function test_mark_paid_is_idempotent_for_already_paid_order(): void
    {
        $order = new Fake_Order('processing');
        $this->orders['42'] = $order;

        $result = $this->make_sync()->mark_paid('42', 'PAY_1', 'cron');

        // 已付款订单不再触发payment_complete（避免重复触发WC的付款完成钩子/邮件）
        $this->assertFalse($result);
        $this->assertNull($order->completed_with_txn);
        // 但缺失的payment id仍要补上，否则后续退款拿不到PAY_id
        $this->assertSame('PAY_1', $order->meta['_waffo_payment_id']);
        $this->assertSame(1, $order->saves);
    }

    public function test_mark_paid_does_not_overwrite_existing_payment_id(): void
    {
        $order = new Fake_Order('completed');
        $order->meta['_waffo_payment_id'] = 'PAY_old';
        $this->orders['42'] = $order;

        $this->make_sync()->mark_paid('42', 'PAY_new', 'cron');

        $this->assertSame('PAY_old', $order->meta['_waffo_payment_id']);
        $this->assertSame(0, $order->saves);
    }

    public function test_mark_paid_returns_false_and_logs_when_order_missing(): void
    {
        $result = $this->make_sync()->mark_paid('999', 'PAY_1', 'webhook');

        $this->assertFalse($result);
        $this->assertCount(1, $this->logs);
        $this->assertStringContainsString('999', $this->logs[0]);
    }

    public function test_mark_canceled_only_touches_awaiting_payment_orders(): void
    {
        $this->orders['1'] = new Fake_Order('on-hold');
        $this->orders['2'] = new Fake_Order('pending');
        $this->orders['3'] = new Fake_Order('processing');

        $sync = $this->make_sync();
        $this->assertTrue($sync->mark_canceled('1', 'checkout expired'));
        $this->assertTrue($sync->mark_canceled('2', 'checkout expired'));
        $this->assertFalse($sync->mark_canceled('3', 'checkout expired'));

        $this->assertSame('cancelled', $this->orders['1']->status);
        $this->assertSame('cancelled', $this->orders['2']->status);
        $this->assertSame('processing', $this->orders['3']->status);
    }

    public function test_apply_webhook_event_routes_order_completed(): void
    {
        $this->orders['42'] = new Fake_Order('on-hold');

        $this->make_sync()->apply_webhook_event([
            'eventType' => 'order.completed',
            'eventId'   => 'PAY_1',
            'data'      => ['orderMerchantExternalId' => '42', 'paymentId' => 'PAY_1'],
        ]);

        $this->assertSame('processing', $this->orders['42']->status);
        $this->assertSame('PAY_1', $this->orders['42']->meta['_waffo_payment_id']);
    }

    public function test_apply_webhook_event_ignores_events_without_external_id(): void
    {
        // 不是本插件创建的订单（比如商户在Waffo Dashboard直接建的），没有WC订单号，直接跳过
        $this->make_sync()->apply_webhook_event([
            'eventType' => 'order.completed',
            'eventId'   => 'PAY_1',
            'data'      => ['paymentId' => 'PAY_1'],
        ]);

        $this->assertEmpty($this->logs);
    }

    public function test_apply_webhook_event_adds_note_for_refund_events(): void
    {
        $order = new Fake_Order('processing');
        $this->orders['42'] = $order;

        $sync = $this->make_sync();
        $sync->apply_webhook_event([
            'eventType' => 'refund.succeeded',
            'eventId'   => 'REF_1',
            'data'      => ['orderMerchantExternalId' => '42', 'amount' => '10.00', 'currency' => 'USD', 'refundStatus' => 'succeeded'],
        ]);
        $sync->apply_webhook_event([
            'eventType' => 'refund.failed',
            'eventId'   => 'REF_2',
            'data'      => ['orderMerchantExternalId' => '42', 'amount' => '5.00', 'currency' => 'USD', 'refundStatus' => 'failed'],
        ]);

        $this->assertCount(2, $order->notes);
        $this->assertStringContainsString('10.00 USD', $order->notes[0]);
        $this->assertStringContainsString('failed', $order->notes[1]);
        // 退款事件不改订单状态：WC侧的退款记录在process_refund时已创建，这里只留痕
        $this->assertSame('processing', $order->status);
    }

    public function test_apply_webhook_event_adds_note_for_subscription_events_without_status_change(): void
    {
        $order = new Fake_Order('processing');
        $this->orders['42'] = $order;

        $this->make_sync()->apply_webhook_event([
            'eventType' => 'subscription.canceled',
            'eventId'   => 'ORD_1',
            'data'      => ['orderMerchantExternalId' => '42'],
        ]);

        $this->assertCount(1, $order->notes);
        $this->assertStringContainsString('subscription.canceled', $order->notes[0]);
        $this->assertSame('processing', $order->status);
    }
}
