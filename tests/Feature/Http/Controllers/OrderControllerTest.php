<?php

namespace Tests\Feature\Http\Controllers;

use App\EventBus\EventBusInterface;
use App\Events\DomainEvent;
use App\Models\Order;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_order_and_outbox_event(): void
    {
        $response = $this->postJson('/api/orders', [
            'description' => 'Laptop Dell XPS 15',
            'amount' => 8999.99,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['description' => 'Laptop Dell XPS 15']);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('outbox_events', 1);

        $outbox = OutboxEvent::first();
        $this->assertEquals('order.created', $outbox->event_type);
        $this->assertNull($outbox->published_at);
    }

    public function test_store_transaction_atomicity_both_commit(): void
    {
        $this->postJson('/api/orders', [
            'description' => 'Test atomicity',
            'amount' => 100.00,
        ]);

        $order = Order::first();
        $outbox = OutboxEvent::first();

        $this->assertNotNull($order);
        $this->assertNotNull($outbox);
        $this->assertEquals($order->id, $outbox->payload['payload']['order_id']);
    }

    public function test_store_transaction_rolls_back_both_on_failure(): void
    {
        $failingBus = new class implements EventBusInterface {
            public function publish(DomainEvent $event): void
            {
                throw new \RuntimeException('Simulated EventBus failure');
            }
        };

        $this->app->instance(EventBusInterface::class, $failingBus);

        $response = $this->postJson('/api/orders', [
            'description' => 'Should not persist',
            'amount' => 100.00,
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_store_validates_required_description(): void
    {
        $response = $this->postJson('/api/orders', [
            'amount' => 100.00,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('description');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_store_validates_required_amount(): void
    {
        $response = $this->postJson('/api/orders', [
            'description' => 'Test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amount');
    }

    public function test_store_validates_amount_minimum(): void
    {
        $response = $this->postJson('/api/orders', [
            'description' => 'Test',
            'amount' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amount');
    }

    public function test_store_validates_amount_maximum(): void
    {
        $response = $this->postJson('/api/orders', [
            'description' => 'Test',
            'amount' => 1000000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amount');
    }

    public function test_store_validates_description_max_length(): void
    {
        $response = $this->postJson('/api/orders', [
            'description' => str_repeat('a', 1001),
            'amount' => 100.00,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('description');
    }

    public function test_index_returns_paginated_orders(): void
    {
        Order::factory()->count(3)->create();

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    public function test_show_returns_single_order(): void
    {
        $order = Order::factory()->create([
            'description' => 'Specific order',
            'amount' => 250.00,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['description' => 'Specific order']);
    }

    public function test_store_outbox_event_has_correct_envelope_structure(): void
    {
        $this->postJson('/api/orders', [
            'description' => 'Envelope test',
            'amount' => 55.00,
        ]);

        $outbox = OutboxEvent::first();
        $payload = $outbox->payload;

        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('event_type', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);
        $this->assertArrayHasKey('payload', $payload);
        $this->assertEquals('order.created', $payload['event_type']);
        $this->assertEquals('Envelope test', $payload['payload']['description']);
    }
}
