<?php
namespace WaffoPancake\Dedup;

class In_Memory_Event_Store implements Event_Store_Interface
{
    private array $seen = [];

    public function has(string $event_id): bool
    {
        return isset($this->seen[$event_id]);
    }

    public function put(string $event_id): void
    {
        $this->seen[$event_id] = true;
    }
}
