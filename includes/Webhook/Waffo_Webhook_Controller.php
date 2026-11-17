<?php
namespace WaffoPancake\Webhook;

use WaffoPancake\Signing\Waffo_Webhook_Verifier;
use WaffoPancake\Dedup\Waffo_Event_Deduplicator;

class Waffo_Webhook_Controller
{
    private Waffo_Webhook_Verifier $verifier;
    private Waffo_Event_Deduplicator $dedup;
    /** @var callable */
    private $on_event;

    public function __construct(Waffo_Webhook_Verifier $verifier, Waffo_Event_Deduplicator $dedup, callable $on_event)
    {
        $this->verifier = $verifier;
        $this->dedup = $dedup;
        $this->on_event = $on_event;
    }

    public function register_routes(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('waffo-pancake/v1', '/webhook', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle($request)
    {
        $signature_header = $request->get_header('X-Waffo-Signature') ?? '';
        $raw_body = $request->get_body();

        if (!$this->verifier->verify($signature_header, $raw_body)) {
            return new \WP_REST_Response(['message' => 'Invalid webhook signature'], 401);
        }

        $event = json_decode($raw_body, true);
        if (
            !is_array($event)
            || !isset($event['eventId'], $event['eventType'])
            || !is_string($event['eventId'])
            || !is_string($event['eventType'])
        ) {
            return new \WP_REST_Response(['message' => 'Malformed webhook payload'], 400);
        }

        $dedup_key = self::dedup_key($event['eventType'], $event['eventId']);

        if ($this->dedup->is_duplicate($dedup_key)) {
            return new \WP_REST_Response(['message' => 'Duplicate event, already processed'], 200);
        }

        try {
            ($this->on_event)($event);
        } catch (\Throwable $e) {
            error_log('Waffo webhook on_event handler failed: ' . $e->getMessage());
            return new \WP_REST_Response(['message' => 'Internal error processing webhook'], 500);
        }

        $this->dedup->mark_processed($dedup_key);

        return new \WP_REST_Response(['message' => 'ok'], 200);
    }

    /**
     * 去重键必须是 eventType + eventId 的复合键。
     *
     * 官方文档 eventId Mapping：eventId 指向"触发事件的业务实体"，不同事件类型可以共用同一个
     * eventId——例如 subscription.activated 与 subscription.canceled 的 eventId 都是订单ID ORD_x
     * （生产实测确认）。只按 eventId 去重会把后到的 canceled 当作 activated 的重复投递静默丢弃。
     * 重试投递不会更换 eventId/eventType，所以复合键仍能正确识别重试。
     *
     * 键长上限：transient key 受 option_name 191 字符限制，前缀 waffo_evt_（10）+ 最长事件类型
     * subscription.plan_change_scheduled（34）+ 冒号 + 最长 eventId（ORD_22位 + "-" + ISO时间戳 24位 ≈ 47）
     * 合计 < 100，安全。
     */
    public static function dedup_key(string $event_type, string $event_id): string
    {
        return $event_type . ':' . $event_id;
    }
}
