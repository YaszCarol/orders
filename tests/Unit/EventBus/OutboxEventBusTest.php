<?php

namespace Tests\Unit\EventBus;

use App\EventBus\OutboxEventBus;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboxEventBusTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_creates_outbox_event(): void
    {
        $order = Order::factory()->create();
        $event = new OrderCreated($order);

        $bus = new OutboxEventBus();
        $bus->publish($event);

        $this->assertDatabaseCount('outbox_events', 1);

        $outbox = OutboxEvent::first();
        $this->assertEquals('order.created', $outbox->event_type);
        $this->assertEquals('order.created', $outbox->routing_key);
        $this->assertNull($outbox->published_at);
    }

    public function test_publish_stores_correct_envelope(): void
    {
        $order = Order::factory()->create();
        $event = new OrderCreated($order);

        $bus = new OutboxEventBus();
        $bus->publish($event);

        $outbox = OutboxEvent::first();
        $payload = $outbox->payload;

        $this->assertEquals($event->eventId(), $payload['event_id']);
        $this->assertEquals('order.created', $payload['event_type']);
        $this->assertArrayHasKey('occurred_at', $payload);
        $this->assertEquals($order->id, $payload['payload']['order_id']);
        $this->assertEquals($order->description, $payload['payload']['description']);
        $this->assertEquals($order->amount, $payload['payload']['amount']);
    }

    public function test_publish_inside_transaction_rolls_back_with_order(): void
    {
        try {
            \DB::transaction(function () {
                $order = Order::factory()->create();
                $bus = new OutboxEventBus();
                $bus->publish(new OrderCreated($order));

                throw new \RuntimeException('Simulated failure');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_publish_inside_transaction_commits_both(): void
    {
        \DB::transaction(function () {
            $order = Order::factory()->create();
            $bus = new OutboxEventBus();
            $bus->publish(new OrderCreated($order));
        });

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_multiple_events_create_multiple_outbox_records(): void
    {
        $bus = new OutboxEventBus();

        $order1 = Order::factory()->create();
        $order2 = Order::factory()->create();

        $bus->publish(new OrderCreated($order1));
        $bus->publish(new OrderCreated($order2));

        $this->assertDatabaseCount('outbox_events', 2);

        $events = OutboxEvent::orderBy('created_at')->get();
        $this->assertEquals($order1->id, $events[0]->payload['payload']['order_id']);
        $this->assertEquals($order2->id, $events[1]->payload['payload']['order_id']);
    }

    public function test_outbox_event_created_with_null_published_at(): void
    {
        $order = Order::factory()->create();
        $bus = new OutboxEventBus();
        $bus->publish(new OrderCreated($order));

        $outbox = OutboxEvent::first();
        $this->assertNull($outbox->published_at);
    }
}
