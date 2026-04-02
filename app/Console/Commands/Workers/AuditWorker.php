<?php

namespace App\Console\Commands\Workers;

use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

class AuditWorker extends Command
{
    protected $signature = 'worker:audit';
    protected $description = 'Consume fraud and suspicious order events for audit logging';

    private bool $shouldStop = false;

    public function handle(RabbitMQService $rabbitMQ): int
    {
        $this->info('Audit worker starting...');

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $rabbitMQ->setupTopology();

        $rabbitMQ->consumeAsync('orders.audit', function (AMQPMessage $message) {
            $envelope = json_decode($message->getBody(), true);

            $eventType = $envelope['event_type'] ?? 'unknown';
            $eventId = $envelope['event_id'] ?? 'unknown';
            $occurredAt = $envelope['occurred_at'] ?? now()->toIso8601String();
            $payload = $envelope['payload'] ?? $envelope;

            $orderId = $payload['order_id'] ?? 'unknown';

            $this->info("[AUDIT] {$occurredAt} | {$eventType} | Order {$orderId}");

            Log::channel('single')->info('AUDIT', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'payload' => $payload,
            ]);

            $message->ack();
        });

        $this->info('Listening on queue: orders.audit (order.classified.fraud, order.classified.suspicious)');

        $channel = $rabbitMQ->getChannel();

        while ($channel->is_consuming() && ! $this->shouldStop) {
            try {
                $channel->wait(null, false, 5);
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException) {
                // No messages, continue listening
            }
        }

        $this->info('Audit worker stopped.');
        $rabbitMQ->close();

        return self::SUCCESS;
    }
}
