<?php
namespace WaffoPancake\Dedup;

class Waffo_Event_Deduplicator
{
    private Event_Store_Interface $store;

    public function __construct(Event_Store_Interface $store)
    {
        $this->store = $store;
    }

    public function is_duplicate(string $event_id): bool
    {
        return $this->store->has($event_id);
    }

    public function mark_processed(string $event_id): void
    {
        $this->store->put($event_id);
    }
}
