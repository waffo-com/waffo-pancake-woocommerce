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

        $response = $controller->handle($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(401, $response->get_status());
    }

    public function test_handle_returns_200_and_skips_processing_on_duplicate_event(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);

        $store = new In_Memory_Event_Store();
        $dedup = new Waffo_Event_Deduplicator($store);
        $dedup->mark_processed(Waffo_Webhook_Controller::dedup_key('order.completed', 'PAY_1'));

        $processed = [];
        $controller = new Waffo_Webhook_Controller($verifier, $dedup, function (array $event) use (&$processed) {
            $processed[] = $event;
        });

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('{"eventId":"PAY_1","eventType":"order.completed"}');

        $response = $controller->handle($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
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

        $response = $controller->handle($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $processed);
        $this->assertSame('PAY_2', $processed[0]['eventId']);
        $this->assertTrue($dedup->is_duplicate(Waffo_Webhook_Controller::dedup_key('order.completed', 'PAY_2')));
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

        $response = $controller->handle($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(400, $response->get_status());
    }

    public function test_handle_returns_500_and_does_not_mark_processed_when_on_event_throws(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);

        $store = new In_Memory_Event_Store();
        $dedup = new Waffo_Event_Deduplicator($store);

        $controller = new Waffo_Webhook_Controller($verifier, $dedup, function (array $event) {
            throw new \RuntimeException('downstream order update failed');
        });

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('{"eventId":"PAY_3","eventType":"order.completed"}');

        $response = $controller->handle($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(500, $response->get_status());
        $this->assertFalse($dedup->is_duplicate(Waffo_Webhook_Controller::dedup_key('order.completed', 'PAY_3')), '处理失败的事件不应被标记为已处理，需保留at-least-once重试语义');
    }

    public function test_same_event_id_with_different_event_type_is_not_a_duplicate(): void
    {
        // 生产实测：subscription.activated 与 subscription.canceled 共用同一个 eventId（都是 ORD_x），
        // 只按 eventId 去重会把后到的 canceled 当成重复事件静默丢弃。
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->twice()->andReturn(true);
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $processed = [];
        $controller = new Waffo_Webhook_Controller($verifier, $dedup, function (array $event) use (&$processed) {
            $processed[] = $event['eventType'];
        });

        foreach (['subscription.activated', 'subscription.canceled'] as $type) {
            $request = \Mockery::mock('WP_REST_Request');
            $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
            $request->shouldReceive('get_body')->andReturn(json_encode(['eventId' => 'ORD_1', 'eventType' => $type]));
            $this->assertSame(200, $controller->handle($request)->get_status());
        }

        $this->assertSame(['subscription.activated', 'subscription.canceled'], $processed);
    }

    public function test_handle_returns_400_when_event_type_missing(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $controller = $this->make_controller($verifier, $dedup);

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('{"eventId":"PAY_1"}');

        $this->assertSame(400, $controller->handle($request)->get_status());
    }

    public function test_handle_returns_400_when_event_id_is_not_a_string(): void
    {
        $verifier = \Mockery::mock(Waffo_Webhook_Verifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);
        $dedup = new Waffo_Event_Deduplicator(new In_Memory_Event_Store());

        $controller = $this->make_controller($verifier, $dedup);

        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_header')->with('X-Waffo-Signature')->andReturn('t=1,v1=sig');
        $request->shouldReceive('get_body')->andReturn('{"eventId":12345,"eventType":"order.completed"}');

        $response = $controller->handle($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(400, $response->get_status());
    }
}
