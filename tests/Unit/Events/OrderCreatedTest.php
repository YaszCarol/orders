<?php

namespace Tests\Unit\Events;

use App\Events\OrderCreated;
use App\Models\Order;
use PHPUnit\Framework\TestCase;

class OrderCreatedTest extends TestCase
{
    private function makeOrder(): Order
    {
        $order = new Order();
        $order->id = '01JTEST000000000000000001';
        $order->description = 'Test order';
        $order->amount = 150.00;

        return $order;
    }

    public function test_event_type_is_order_created(): void
    {
        $event = new OrderCreated($this->makeOrder());

        $this->assertEquals('order.created', $event->eventType());
    }

    public function test_payload_contains_order_data(): void
    {
        $order = $this->makeOrder();
        $event = new OrderCreated($order);

        $payload = $event->payload();

        $this->assertEquals($order->id, $payload['order_id']);
        $this->assertEquals($order->description, $payload['description']);
        $this->assertEquals($order->amount, $payload['amount']);
    }

    public function test_event_id_is_unique_per_instance(): void
    {
        $order = $this->makeOrder();

        $event1 = new OrderCreated($order);
        $event2 = new OrderCreated($order);

        $this->assertNotEquals($event1->eventId(), $event2->eventId());
    }

    public function test_event_id_is_ulid_format(): void
    {
        $event = new OrderCreated($this->makeOrder());

        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $event->eventId());
    }

    public function test_occurred_at_is_set_on_creation(): void
    {
        $before = new \DateTimeImmutable();
        $event = new OrderCreated($this->makeOrder());
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $event->occurredAt());
        $this->assertLessThanOrEqual($after, $event->occurredAt());
    }

    public function test_order_is_accessible(): void
    {
        $order = $this->makeOrder();
        $event = new OrderCreated($order);

        $this->assertSame($order, $event->order);
    }
}
