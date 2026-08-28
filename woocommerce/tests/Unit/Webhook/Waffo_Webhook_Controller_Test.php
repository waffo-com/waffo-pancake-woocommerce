<?php
namespace WaffoPancake\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use WaffoPancake\Webhook\Waffo_Webhook_Controller;
use WaffoPancake\Signing\Waffo_Webhook_Verifier;
use WaffoPancake\Dedup\Waffo_Event_Deduplicator;
use WaffoPancake\Dedup\In_Memory_Event_Store;

class Waffo_Webhook_Controller_Test extends TestCase
{
    protected function setUp(): void
    {
        \WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        \WP_Mock::tearDown();
    }

    private function make_controller(Waffo_Webhook_Verifier $verifier, Waffo_Event_Deduplicator $dedup): Waffo_Webhook_Controller
    {
        $handled = [];
        return new Waffo_Webhook_Controller($verifier, $dedup, function (array $event) use (&$handled) {
            $handled[] = $event;
            return $handled;
        });
    }

    public function test_register_routes_registers_expected_route(): void
    {
        \WP_Mock::expectActionAdded('rest_api_init', \Mockery::type(\Closure::class));

        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());
        $controller = $this->make_controller($verifier, $dedup);

        $controller->register_routes();

        \WP_Mock::assertHooksAdded();
    }

    public function test_handle_returns_401_on_invalid_signature(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(false);
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $controller = $this->make_controller($verifier, $dedup);

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=bad');
        $request->shouldReceive('get_body')->andReturn('{"eventId":"PAY_1"}');

        \WP_Mock::userFunction('rest_ensure_response')->andReturnUsing(fn ($data) => $data);

        $response = $controller->handle($request);

        $this->assertSame(401, $response['status'] ?? null);
    }

    public function test_handle_returns_200_and_skips_processing_on_duplicate_event(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);

        $store = new In_Memory_Event_Store();
        $dedup = new Waffo_Event_Deduplicator($store);
        $dedup->mark_processed('PAY_1');

        $processed = [];
        $controller = new Waffo_Webhook_Controller($verifier, $dedup, function (array $event) use (&$processed) {
            $processed[] = $event;
        });

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('{"eventId":"PAY_1","eventType":"order.completed"}');

        \WP_Mock::userFunction('rest_ensure_response')->andReturnUsing(fn ($data) => $data);

        $response = $controller->handle($request);

        $this->assertSame(200, $response['status'] ?? null);
        $this->assertEmpty($processed, '重复事件不应触发处理回调');
    }

    public function test_handle_processes_new_event_and_marks_it_seen(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);

        $store = new In_Memory_Event_Store();
        $dedup = new Waffo_Event_Deduplicator($store);

        $processed = [];
        $controller = new Waffo_Webhook_Controller($verifier, $dedup, function (array $event) use (&$processed) {
            $processed[] = $event;
        });

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('{"eventId":"PAY_2","eventType":"order.completed"}');

        \WP_Mock::userFunction('rest_ensure_response')->andReturnUsing(fn ($data) => $data);

        $response = $controller->handle($request);

        $this->assertSame(200, $response['status'] ?? null);
        $this->assertCount(1, $processed);
        $this->assertSame('PAY_2', $processed[0]['eventId']);
        $this->assertTrue($dedup->is_duplicate('PAY_2'));
    }

    public function test_handle_returns_400_on_malformed_json_body(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $controller = $this->make_controller($verifier, $dedup);

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('not json');

        \WP_Mock::userFunction('rest_ensure_response')->andReturnUsing(fn ($data) => $data);

        $response = $controller->handle($request);

        $this->assertSame(400, $response['status'] ?? null);
    }
}
