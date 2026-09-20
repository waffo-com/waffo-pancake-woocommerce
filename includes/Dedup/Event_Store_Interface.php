<?php
namespace WaffoPancake\Dedup;

interface Event_Store_Interface
{
    /**
     * 判断该事件是否已被处理过。
     *
     * 实现方约定：`put()` 只能存储真值（如 `true`）作为"已处理"标记，
     * 不能存储可能为 falsy 的业务数据（如时间戳 0、空字符串等），
     * 否则本方法可能因 PHP 的 falsy 语义而误判为"未处理"，从而破坏去重保证。
     *
     * 注意：本方法与 put() 之间不保证原子性/线程安全。并发 webhook 投递之间，
     * has() 和 put() 存在竞态窗口（先后调用之间可能被另一请求抢先写入），
     * 调用方不应假设"先查后写"是原子操作。
     */
    public function has(string $event_id): bool;

    /**
     * 记录该事件已被处理。
     *
     * 实现方约定：只能存储真值（如 `true`），不要存储可能为 falsy 的业务数据，
     * 详见 has() 的说明。
     *
     * 注意：本方法不保证原子性/线程安全，并发调用之间存在竞态窗口，详见 has() 的说明。
     */
    public function put(string $event_id): void;
}
