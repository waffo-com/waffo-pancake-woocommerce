<?php
namespace WaffoPancake\Dedup;

class WP_Transient_Event_Store implements Event_Store_Interface
{
    // 7天过期：远超Waffo webhook可能的重试窗口（重试策略本身待确认，见设计文档第10节）
    private const TTL_SECONDS = 7 * DAY_IN_SECONDS;

    public function has(string $event_id): bool
    {
        return get_transient($this->key($event_id)) !== false;
    }

    public function put(string $event_id): void
    {
        set_transient($this->key($event_id), true, self::TTL_SECONDS);
    }

    private function key(string $event_id): string
    {
        return 'waffo_evt_' . $event_id;
    }
}
