<?php

namespace App\Console\Commands;

use App\Services\RabbitMQService;
use Illuminate\Console\Command;

class ConsumeOrders extends Command
{
    protected $signature = 'orders:consume';
    protected $description = 'Consume orders from RabbitMQ queues and process them';

    private bool $shouldStop = false;

    public function handle(RabbitMQService $rabbitMQ): int
    {
        $this->info('Starting order consumer...');

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
        pcntl_signal(SIGINT, fn () => $this->shouldStop = true);

        $rabbitMQ->connect();

        $queues = config('rabbitmq.queues');

        foreach ($queues as $level => $queue) {
            $this->registerConsumer($rabbitMQ, $queue, $level);
        }

        $this->info('Listening on queues: ' . implode(', ', $queues));

        $channel = $rabbitMQ->getChannel();

        while ($channel->is_consuming() && ! $this->shouldStop) {
            $channel->wait(null, false, 5);
        }

        $this->info('Consumer stopped gracefully.');
        $rabbitMQ->close();

        return self::SUCCESS;
    }

    private function registerConsumer(RabbitMQService $rabbitMQ, string $queue, string $level): void
    {
        $rabbitMQ->consumeAsync($queue, function (array $data) use ($level, $queue) {
            $orderId = $data['order_id'] ?? 'unknown';

            match ($level) {
                'safe' => $this->info("[{$queue}] Order {$orderId}: APPROVED - {$data['reasoning']}"),
                'suspicious' => $this->warn("[{$queue}] Order {$orderId}: REVIEWING - {$data['reasoning']}"),
                'fraud' => $this->error("[{$queue}] Order {$orderId}: BLOCKED - {$data['reasoning']}"),
            };
        });
    }
}
