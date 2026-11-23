<?php
/**
 * Run with wp eval-file on a disposable WordPress + WooCommerce installation.
 * The installed release ZIP supplies the code under test. A fresh RSA key replaces
 * only the route's verifier so signed fixtures never require platform credentials.
 */
use WaffoPancake\Dedup\Waffo_Event_Deduplicator;
use WaffoPancake\Dedup\WP_Transient_Event_Store;
use WaffoPancake\Order\Waffo_Order_Sync;
use WaffoPancake\Signing\Waffo_Webhook_Verifier;
use WaffoPancake\Webhook\Waffo_Webhook_Controller;

// Disposable integration fixtures must never send customer or administrator email.
add_filter('pre_wp_mail', '__return_true');

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    ++$checks;
    echo "PASS: $message\n";
};
$assert(WP_DEBUG === true, 'WP_DEBUG is enabled');
$assert(class_exists('WC_Order'), 'WooCommerce is active');
$assert(WAFFO_PANCAKE_WC_VERSION === '0.1.2', 'release ZIP version 0.1.2 is installed');
$server = rest_get_server();
$route = '/waffo-pancake/v1/webhook';
$endpoints = $server->get_routes()[$route];
$assert($endpoints[0]['permission_callback'][1] === 'authorize', 'production route has an authorization callback');
$unsigned = new WP_REST_Request('POST', $route);
$unsigned->set_body('{}');
$assert(rest_do_request($unsigned)->get_status() === 401, 'production route rejects unsigned notifications');

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$verifier = new Waffo_Webhook_Verifier(openssl_pkey_get_details($key)['key']);
$dedup = new Waffo_Event_Deduplicator(new WP_Transient_Event_Store());
$sync = new Waffo_Order_Sync(static fn (string $id) => wc_get_order((int) $id) ?: null);
$controller = new Waffo_Webhook_Controller($verifier, $dedup, [$sync, 'apply_webhook_event'], 'STO_review');
$server->register_route('waffo-pancake/v1', $route, [[
    'methods' => 'POST', 'callback' => [$controller, 'handle'], 'permission_callback' => [$controller, 'authorize'],
]], true);
$dispatch = static function (array $event, bool $valid_signature = true) use ($key, $route): WP_REST_Response {
    $body = wp_json_encode($event);
    $timestamp = (string) (int) (microtime(true) * 1000);
    openssl_sign($timestamp . '.' . $body, $signature, $key, OPENSSL_ALGO_SHA256);
    $request = new WP_REST_Request('POST', $route);
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-Waffo-Signature', 't=' . $timestamp . ',v1=' . ($valid_signature ? base64_encode($signature) : 'bad'));
    $request->set_body($body);
    return rest_do_request($request);
};
$make_order = static function (string $method = 'waffo_pancake', bool $session = true): WC_Order {
    $order = wc_create_order();
    $order->set_payment_method($method);
    $order->set_status('on-hold');
    $order->set_total('10.00');
    if ($session) {
        $order->update_meta_data('_waffo_checkout_session_id', 'CS_review');
    }
    $order->save();
    return $order;
};
$event_for = static function (WC_Order $order, string $type = 'order.completed'): array {
    return [
        'storeId' => 'STO_review', 'mode' => 'test', 'eventId' => 'EVT_' . wp_generate_uuid4(),
        'eventType' => $type,
        'data' => ['orderMerchantExternalId' => (string) $order->get_id(), 'paymentId' => 'PAY_review'],
    ];
};
$snapshot = static function (WC_Order $order): array {
    $fresh = wc_get_order($order->get_id());
    return [$fresh->get_status(), $fresh->get_transaction_id(), $fresh->get_meta('_waffo_payment_id'),
        count(wc_get_order_notes(['order_id' => $order->get_id()])), count($fresh->get_refunds())];
};

$order = $make_order();
$before = $snapshot($order);
$event = $event_for($order);
foreach (['another store' => 'STO_other', 'missing store' => null, 'empty store' => '', 'invalid store type' => ['STO_review']] as $label => $store) {
    $untrusted = array_replace($event, ['storeId' => $store]);
    $assert($dispatch($untrusted)->get_status() === 403, "$label is rejected despite a valid signature");
    $assert($snapshot($order) === $before, "$label leaves the order unchanged");
    $assert(!$dedup->is_duplicate(Waffo_Webhook_Controller::dedup_key($event['eventType'], $event['eventId'])), "$label does not consume the event ID");
}
$assert($dispatch($event, false)->get_status() === 401, 'bad signature is rejected');
$assert($snapshot($order) === $before, 'bad signature leaves the order unchanged');

foreach (['stripe', ''] as $method) {
    foreach (['order.completed', 'refund.succeeded', 'refund.failed', 'subscription.canceled'] as $type) {
        $unrelated = $make_order($method);
        $before = $snapshot($unrelated);
        $assert($dispatch($event_for($unrelated, $type))->get_status() === 200, "$type acknowledges an unrelated order without retrying");
        $assert($snapshot($unrelated) === $before, "$type cannot modify an order with payment method '$method'");
    }
}
$no_checkout = $make_order('waffo_pancake', false);
$before = $snapshot($no_checkout);
$dispatch($event_for($no_checkout));
$assert($snapshot($no_checkout) === $before, 'Pancake order without a checkout session is unchanged');

$assert($dispatch($event)->get_status() === 200, 'valid notification for this store is processed');
$paid = wc_get_order($order->get_id());
$assert($paid->is_paid(), 'real WooCommerce order is marked paid');
$assert($paid->get_meta('_waffo_payment_id') === 'PAY_review', 'payment ID is saved');
$before = $snapshot($order);
$assert($dispatch($event)->get_status() === 200, 'duplicate delivery is acknowledged');
$assert($snapshot($order) === $before, 'duplicate delivery does not add notes or repeat payment completion');
$assert($dispatch(array_replace($event, ['storeId' => 'STO_other']))->get_status() === 403, 'store authorization runs before duplicate detection');

$refunded = $make_order();
$refunded->update_status('completed');
$refund = wc_create_refund(['order_id' => $refunded->get_id(), 'amount' => 10, 'refund_payment' => false]);
$assert($refund instanceof WC_Order_Refund, 'real refund record is created');
$refund->update_meta_data(Waffo_Order_Sync::META_REFUND_REF, 'review_refund');
$refund->update_meta_data(Waffo_Order_Sync::META_REFUND_PRIOR_STATUS, 'completed');
$refund->save();
$failure = $event_for($refunded, 'refund.failed');
$failure['data']['refundTicketMerchantExternalId'] = 'review_refund';
$before = $snapshot($refunded);
$assert($dispatch(array_replace($failure, ['storeId' => 'STO_other']))->get_status() === 403, 'foreign refund failure is rejected');
$assert($snapshot($refunded) === $before, 'foreign refund failure cannot delete a refund');
$assert($dispatch($failure)->get_status() === 200, 'authorized refund failure is handled');
$assert(wc_get_order($refund->get_id()) === false, 'authorized refund failure removes its matching refund');
$assert(wc_get_order($refunded->get_id())->get_status() === 'completed', 'authorized refund failure restores order status');

$controller = new Waffo_Webhook_Controller($verifier, $dedup, [$sync, 'apply_webhook_event'], '');
$server->register_route('waffo-pancake/v1', $route, [[
    'methods' => 'POST', 'callback' => [$controller, 'handle'], 'permission_callback' => [$controller, 'authorize'],
]], true);
$assert($dispatch($event_for($order))->get_status() === 503, 'unconfigured store fails closed');
echo wp_json_encode(['checks' => $checks, 'wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => DB_ENGINE]) . "\n";
