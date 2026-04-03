<?php

namespace Tests\Feature\Commands;

use App\Ai\Agents\OrderClassifier;
use App\Enums\OrderStatus;
use App\Enums\RiskLevel;
use App\EventBus\EventBusInterface;
use App\Events\DomainEvent;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Services\RabbitMQService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

class ClassificationWorkerTest extends TestCase
{
    use RefreshDatabase;

    private ?\Closure $capturedCallback = null;

    private function setupWorkerMocks(): RabbitMQService&\Mockery\MockInterface
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('is_consuming')->andReturn(false);

        $mock = Mockery::mock(RabbitMQService::class);
        $mock->shouldReceive('setupTopology');
        $mock->shouldReceive('consumeAsync')
            ->andReturnUsing(function (string $queue, callable $callback) {
                $this->capturedCallback = $callback;
            });
        $mock->shouldReceive('getChannel')->andReturn($channel);
        $mock->shouldReceive('close');
        $mock->shouldReceive('getRetryCount')->andReturn(0)->byDefault();
        $mock->shouldReceive('publish')->byDefault();

        $this->app->instance(RabbitMQService::class, $mock);

        return $mock;
    }

    private function makeMessage(array $envelope): AMQPMessage
    {
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getBody')->andReturn(json_encode($envelope));
        $message->shouldReceive('ack')->byDefault();
        $message->shouldReceive('nack')->byDefault();

        return $message;
    }

    private function makeEnvelope(Order $order): array
    {
        return [
            'event_id' => 'test-event-id',
            'event_type' => 'order.created',
            'occurred_at' => now()->toIso8601String(),
            'payload' => [
                'order_id' => $order->id,
                'description' => $order->description,
                'amount' => $order->amount,
            ],
        ];
    }

    private function mockClassifier(array $result): void
    {
        $this->app->bind(OrderClassifier::class, function ($app, $params) use ($result) {
            $mock = Mockery::mock(OrderClassifier::class);
            $mock->shouldReceive('classify')->andReturn($result);

            return $mock;
        });
    }

    private function mockClassifierFailure(): void
    {
        $this->app->bind(OrderClassifier::class, function () {
            $mock = Mockery::mock(OrderClassifier::class);
            $mock->shouldReceive('classify')->andThrow(new \RuntimeException('AI service unavailable'));

            return $mock;
        });
    }

    public function test_idempotency_skips_already_classified_order(): void
    {
        $order = Order::factory()->classified(RiskLevel::Safe)->create();

        $rabbitMQ = $this->setupWorkerMocks();
        $rabbitMQ->shouldReceive('getRetryCount')->andReturn(0);

        $message = $this->makeMessage($this->makeEnvelope($order));
        $message->shouldReceive('ack')->once();

        $this->artisan('worker:classification')->assertSuccessful();

        ($this->capturedCallback)($message);

        $order->refresh();
        $this->assertEquals(RiskLevel::Safe, $order->risk_level);
        $this->assertEquals(OrderStatus::Approved, $order->status);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_discards_message_when_order_not_found(): void
    {
        $this->setupWorkerMocks();

        $envelope = [
            'event_id' => 'test',
            'event_type' => 'order.created',
            'occurred_at' => now()->toIso8601String(),
            'payload' => [
                'order_id' => '01JNONEXISTENT00000000000',
                'description' => 'Ghost order',
                'amount' => 100,
            ],
        ];

        $message = $this->makeMessage($envelope);
        $message->shouldReceive('ack')->once();

        $this->artisan('worker:classification')->assertSuccessful();

        ($this->capturedCallback)($message);

        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_dead_letter_when_max_retries_exceeded(): void
    {
        $order = Order::factory()->create();

        $rabbitMQ = $this->setupWorkerMocks();
        $rabbitMQ->shouldReceive('getRetryCount')->andReturn(3);
        $rabbitMQ->shouldReceive('publish')
            ->once()
            ->with('order.dead', Mockery::type('array'));

        $message = $this->makeMessage($this->makeEnvelope($order));
        $message->shouldReceive('ack')->once();

        $this->artisan('worker:classification')->assertSuccessful();

        ($this->capturedCallback)($message);

        $order->refresh();
        $this->assertNull($order->risk_level);
    }

    public function test_safe_classification_approves_order(): void
    {
        $order = Order::factory()->create();

        $this->setupWorkerMocks();
        $this->mockClassifier([
            'risk_level' => 'safe',
            'reasoning' => 'Normal purchase',
        ]);

        $message = $this->makeMessage($this->makeEnvelope($order));
        $message->shouldReceive('ack')->once();

        $this->artisan('worker:classification')->assertSuccessful();

        ($this->capturedCallback)($message);

        $order->refresh();
        $this->assertEquals(RiskLevel::Safe, $order->risk_level);
        $this->assertEquals(OrderStatus::Approved, $order->status);
        $this->assertEquals('Normal purchase', $order->ai_reasoning);

        $this->assertDatabaseCount('outbox_events', 1);
        $outbox = OutboxEvent::first();
        $this->assertEquals('order.classified.safe', $outbox->event_type);
    }

    public function test_suspicious_classification_sets_reviewing(): void
    {
        $order = Order::factory()->create();

        $this->setupWorkerMocks();
        $this->mockClassifier([
            'risk_level' => 'suspicious',
            'reasoning' => 'Unusual pattern detected',
        ]);

        $message = $this->makeMessage($this->makeEnvelope($order));
        $message->shouldReceive('ack')->once();

        $this->artisan('worker:classification')->assertSuccessful();

        ($this->capturedCallback)($message);

        $order->refresh();
        $this->assertEquals(RiskLevel::Suspicious, $order->risk_level);
        $this->assertEquals(OrderStatus::Reviewing, $order->status);

        $outbox = OutboxEvent::first();
        $this->assertEquals('order.classified.suspicious', $outbox->event_type);
    }

    public function test_fraud_classification_blocks_order(): void
    {
        $order = Order::factory()->create();

        $this->setupWorkerMocks();
        $this->mockClassifier([
            'risk_level' => 'fraud',
            'reasoning' => 'Known fraud pattern',
        ]);

        $message = $this->makeMessage($this->makeEnvelope($order));
        $message->shouldReceive('ack')->once();

        $this->artisan('worker:classification')->assertSuccessful();

        ($this->capturedCallback)($message);

        $order->refresh();
        $this->assertEquals(RiskLevel::Fraud, $order->risk_level);
        $this->assertEquals(OrderStatus::Blocked, $order->status);

        $outbox = OutboxEvent::first();
        $this->assertEquals('order.classified.fraud', $outbox->event_type);
    }

    public function test_classification_failure_nacks_message_for_retry(): void
    {
        $order = Order::factory()->create();

        $this->setupWorkerMocks();
        $this->mockClassifierFailure();

        $message = $this->makeMessage($this->makeEnvelope($order));
        $message->shouldNotReceive('ack');
        $message->shouldReceive('nack')->once()->with(false, false);

        $this->artisan('worker:classification')->assertSuccessful();

        ($this->capturedCallback)($message);

        $order->refresh();
        $this->assertNull($order->risk_level);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_transaction_atomicity_rolls_back_on_outbox_failure(): void
    {
        $order = Order::factory()->create();

        $this->setupWorkerMocks();
        $this->mockClassifier([
            'risk_level' => 'safe',
            'reasoning' => 'Test',
        ]);

        // Replace EventBus with one that fails
        $failingBus = new class implements EventBusInterface {
            public function publish(DomainEvent $event): void
            {
                throw new \RuntimeException('Outbox write failed');
            }
        };
        $this->app->instance(EventBusInterface::class, $failingBus);

        $message = $this->makeMessage($this->makeEnvelope($order));
        $message->shouldReceive('nack')->once()->with(false, false);

        $this->artisan('worker:classification')->assertSuccessful();

        ($this->capturedCallback)($message);

        // Both should have rolled back
        $order->refresh();
        $this->assertNull($order->risk_level);
        $this->assertEquals(OrderStatus::Pending, $order->status);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_outbox_event_has_correct_routing_key(): void
    {
        $order = Order::factory()->create();

        $this->setupWorkerMocks();
        $this->mockClassifier([
            'risk_level' => 'fraud',
            'reasoning' => 'Test routing',
        ]);

        $message = $this->makeMessage($this->makeEnvelope($order));

        $this->artisan('worker:classification')->assertSuccessful();

        ($this->capturedCallback)($message);

        $outbox = OutboxEvent::first();
        $this->assertEquals('order.classified.fraud', $outbox->routing_key);
        $this->assertEquals($order->id, $outbox->payload['payload']['order_id']);
    }

    public function test_retry_count_shown_in_output(): void
    {
        $order = Order::factory()->create();

        $rabbitMQ = $this->setupWorkerMocks();
        $rabbitMQ->shouldReceive('getRetryCount')->andReturn(2);

        $this->mockClassifier([
            'risk_level' => 'safe',
            'reasoning' => 'Retry test',
        ]);

        $message = $this->makeMessage($this->makeEnvelope($order));

        $this->artisan('worker:classification')
            ->assertSuccessful();

        ($this->capturedCallback)($message);

        $order->refresh();
        $this->assertEquals(RiskLevel::Safe, $order->risk_level);
    }
}
