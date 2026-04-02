<?php

namespace App\Console\Commands\Workers;

use App\Ai\Agents\OrderClassifier;
use App\Enums\OrderStatus;
use App\Enums\RiskLevel;
use App\EventBus\EventBusInterface;
use App\Events\OrderClassified;
use App\Models\Order;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

class ClassificationWorker extends Command
{
    protected $signature = 'worker:classification';
    protected $description = 'Consume order.created events, classify with AI and emit order.classified.*';

    private bool $shouldStop = false;

    public function handle(RabbitMQService $rabbitMQ, EventBusInterface $eventBus): int
    {
        $this->info('Classification worker starting...');

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $rabbitMQ->setupTopology();

        $maxAttempts = config('rabbitmq.retry.max_attempts');

        $rabbitMQ->consumeAsync('orders.created', function (AMQPMessage $message) use ($rabbitMQ, $eventBus, $maxAttempts) {
            $envelope = json_decode($message->getBody(), true);
            $data = $envelope['payload'] ?? $envelope;
            $orderId = $data['order_id'] ?? 'unknown';

            $retryCount = $rabbitMQ->getRetryCount($message);

            // Max retries exceeded → dead letter
            if ($retryCount >= $maxAttempts) {
                $this->error("Order {$orderId}: max retries ({$maxAttempts}) exceeded → dead-letter");
                Log::error("Order {$orderId} sent to dead-letter after {$retryCount} attempts");
                $rabbitMQ->publish('order.dead', $envelope);
                $message->ack();
                return;
            }

            $order = Order::find($orderId);

            if (! $order) {
                $this->error("Order {$orderId} not found, discarding.");
                $message->ack();
                return;
            }

            // Idempotency — already classified
            if ($order->risk_level !== null) {
                $this->warn("Order {$orderId} already classified as {$order->risk_level->value}, skipping.");
                $message->ack();
                return;
            }

            $this->info("Classifying order {$orderId}..." . ($retryCount > 0 ? " (retry {$retryCount})" : ''));

            try {
                $classifier = app(OrderClassifier::class, ['order' => $order]);
                $result = $classifier->classify();

                $riskLevel = RiskLevel::from($result['risk_level']);
                $status = match ($riskLevel) {
                    RiskLevel::Safe => OrderStatus::Approved,
                    RiskLevel::Suspicious => OrderStatus::Reviewing,
                    RiskLevel::Fraud => OrderStatus::Blocked,
                };

                DB::transaction(function () use ($order, $riskLevel, $result, $status, $eventBus) {
                    $order->update([
                        'risk_level' => $riskLevel,
                        'ai_reasoning' => $result['reasoning'],
                        'status' => $status,
                    ]);

                    $eventBus->publish(new OrderClassified($order));
                });

                $this->info("Order {$orderId} → {$riskLevel->value}");
                Log::info("Order {$orderId} classified as {$riskLevel->value}");

                $message->ack();
            } catch (\Throwable $e) {
                $this->error("Order {$orderId} classification failed: {$e->getMessage()}");
                Log::error("Classification failed for order {$orderId}: {$e->getMessage()}");

                // nack without requeue → goes to retry queue via DLX
                $message->nack(false, false);
            }
        });

        $this->info('Listening on queue: orders.created');

        $channel = $rabbitMQ->getChannel();

        while ($channel->is_consuming() && ! $this->shouldStop) {
            try {
                $channel->wait(null, false, 5);
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException) {
                // No messages, continue listening
            }
        }

        $this->info('Classification worker stopped.');
        $rabbitMQ->close();

        return self::SUCCESS;
    }
}
