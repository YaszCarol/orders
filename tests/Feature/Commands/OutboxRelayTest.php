<?php

namespace Tests\Feature\Commands;

use App\Models\OutboxEvent;
use App\Services\RabbitMQService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    private function mockRabbitMQ(): RabbitMQService&\Mockery\MockInterface
    {
        $mock = Mockery::mock(RabbitMQService::class);
        $mock->shouldReceive('setupTopology')->byDefault();
        $mock->shouldReceive('publish')->byDefault();
        $this->app->instance(RabbitMQService::class, $mock);

        return $mock;
    }

    public function test_relay_publishes_pending_events(): void
    {
        $event = OutboxEvent::factory()->create();

        $mock = $this->mockRabbitMQ();
        $mock->shouldReceive('setupTopology')->once();
        $mock->shouldReceive('publish')
            ->once()
            ->with($event->routing_key, $event->payload);

        $this->artisan('outbox:relay')
            ->assertSuccessful();

        $event->refresh();
        $this->assertNotNull($event->published_at);
    }

    public function test_relay_skips_already_published_events(): void
    {
        OutboxEvent::factory()->published()->create();

        $mock = $this->mockRabbitMQ();
        $mock->shouldNotReceive('setupTopology');
        $mock->shouldNotReceive('publish');

        $this->artisan('outbox:relay')
            ->assertSuccessful();
    }

    public function test_relay_processes_events_in_chronological_order(): void
    {
        $event1 = OutboxEvent::factory()->create(['created_at' => now()->subMinutes(2)]);
        $event2 = OutboxEvent::factory()->create(['created_at' => now()->subMinute()]);
        $event3 = OutboxEvent::factory()->create(['created_at' => now()]);

        $publishedOrder = [];

        $mock = $this->mockRabbitMQ();
        $mock->shouldReceive('publish')
            ->times(3)
            ->andReturnUsing(function (string $routingKey, array $payload) use (&$publishedOrder) {
                $publishedOrder[] = $payload['payload']['order_id'];
            });

        $this->artisan('outbox:relay')->assertSuccessful();

        $this->assertEquals(
            [$event1->payload['payload']['order_id'], $event2->payload['payload']['order_id'], $event3->payload['payload']['order_id']],
            $publishedOrder
        );
    }

    public function test_relay_stops_on_publish_failure(): void
    {
        $event1 = OutboxEvent::factory()->create(['created_at' => now()->subMinute()]);
        $event2 = OutboxEvent::factory()->create(['created_at' => now()]);

        $mock = $this->mockRabbitMQ();
        $mock->shouldReceive('publish')
            ->once()
            ->andThrow(new \RuntimeException('RabbitMQ down'));

        $this->artisan('outbox:relay')->assertSuccessful();

        $event1->refresh();
        $event2->refresh();

        $this->assertNull($event1->published_at);
        $this->assertNull($event2->published_at);
    }

    public function test_relay_marks_successful_events_before_failure(): void
    {
        $event1 = OutboxEvent::factory()->create(['created_at' => now()->subMinute()]);
        $event2 = OutboxEvent::factory()->create(['created_at' => now()]);

        $callCount = 0;
        $mock = $this->mockRabbitMQ();
        $mock->shouldReceive('publish')
            ->twice()
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    throw new \RuntimeException('RabbitMQ down');
                }
            });

        $this->artisan('outbox:relay')->assertSuccessful();

        $event1->refresh();
        $event2->refresh();

        $this->assertNotNull($event1->published_at);
        $this->assertNull($event2->published_at);
    }

    public function test_relay_respects_batch_option(): void
    {
        OutboxEvent::factory()->count(5)->create();

        $mock = $this->mockRabbitMQ();
        $mock->shouldReceive('publish')->times(2);

        $this->artisan('outbox:relay', ['--batch' => 2])
            ->assertSuccessful();

        $this->assertEquals(2, OutboxEvent::whereNotNull('published_at')->count());
        $this->assertEquals(3, OutboxEvent::whereNull('published_at')->count());
    }

    public function test_relay_succeeds_with_no_pending_events(): void
    {
        $this->artisan('outbox:relay')
            ->assertSuccessful();
    }

    public function test_relay_does_not_republish_already_published(): void
    {
        OutboxEvent::factory()->published()->create();
        $pending = OutboxEvent::factory()->create();

        $mock = $this->mockRabbitMQ();
        $mock->shouldReceive('publish')->once();

        $this->artisan('outbox:relay')->assertSuccessful();

        $pending->refresh();
        $this->assertNotNull($pending->published_at);
    }
}
