<?php
namespace WaffoPancake\Tests\Unit\Dedup;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Dedup\Waffo_Event_Deduplicator;
use WaffoPancake\Dedup\In_Memory_Event_Store;

class Waffo_Event_Deduplicator_Test extends TestCase
{
    public function test_first_time_event_is_not_duplicate(): void
    {
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $this->assertFalse($dedup->is_duplicate('PAY_1'));
    }

    public function test_marking_processed_makes_subsequent_check_duplicate(): void
    {
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $dedup->mark_processed('PAY_1');

        $this->assertTrue($dedup->is_duplicate('PAY_1'));
    }

    public function test_different_event_ids_are_independent(): void
    {
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $dedup->mark_processed('PAY_1');

        $this->assertFalse($dedup->is_duplicate('PAY_2'));
    }
}
