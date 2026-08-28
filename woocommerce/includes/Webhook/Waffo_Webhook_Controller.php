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
            return rest_ensure_response(['status' => 401, 'message' => 'Invalid webhook signature']);
        }

        $event = json_decode($raw_body, true);
        if (!is_array($event) || !isset($event['eventId'])) {
            return rest_ensure_response(['status' => 400, 'message' => 'Malformed webhook payload']);
        }

        if ($this->dedup->is_duplicate($event['eventId'])) {
            return rest_ensure_response(['status' => 200, 'message' => 'Duplicate event, already processed']);
        }

        ($this->on_event)($event);
        $this->dedup->mark_processed($event['eventId']);

        return rest_ensure_response(['status' => 200, 'message' => 'ok']);
    }
}
