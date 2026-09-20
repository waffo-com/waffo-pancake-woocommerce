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
        if (!is_array($event) || !isset($event['eventId']) || !is_string($event['eventId'])) {
            return new \WP_REST_Response(['message' => 'Malformed webhook payload'], 400);
        }

        if ($this->dedup->is_duplicate($event['eventId'])) {
            return new \WP_REST_Response(['message' => 'Duplicate event, already processed'], 200);
        }

        try {
            ($this->on_event)($event);
        } catch (\Throwable $e) {
            error_log('Waffo webhook on_event handler failed: ' . $e->getMessage());
            return new \WP_REST_Response(['message' => 'Internal error processing webhook'], 500);
        }

        $this->dedup->mark_processed($event['eventId']);

        return new \WP_REST_Response(['message' => 'ok'], 200);
    }
}
