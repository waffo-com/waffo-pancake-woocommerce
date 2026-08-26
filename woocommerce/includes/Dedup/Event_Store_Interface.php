<?php
namespace WaffoPancake\Dedup;

interface Event_Store_Interface
{
    public function has(string $event_id): bool;
    public function put(string $event_id): void;
}
