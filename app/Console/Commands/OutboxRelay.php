<?php

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutboxRelay extends Command
{
    protected $signature = 'outbox:relay {--batch=100 : Number of events to process per run}';
    protected $description = 'Publish pending outbox events to RabbitMQ (at-least-once delivery)';

    public function handle(RabbitMQService $rabbitMQ): int
    {
        $batch = (int) $this->option('batch');

        $events = OutboxEvent::whereNull('published_at')
            ->orderBy('created_at')
            ->limit($batch)
            ->get();

        if ($events->isEmpty()) {
            return self::SUCCESS;
        }

        $this->info("Publishing {$events->count()} outbox events...");

        $rabbitMQ->setupTopology();

        $published = 0;

        foreach ($events as $event) {
            try {
                $rabbitMQ->publish($event->routing_key, $event->payload);

                // Se o processo crashar entre o publish e o update,
                // a mensagem será republicada na próxima execução.
                // Isso é seguro porque os consumidores são idempotentes
                // (ex: ClassificationWorker verifica risk_level !== null).
                $event->update(['published_at' => now()]);
                $published++;
            } catch (\Throwable $e) {
                $this->error("Event {$event->id} failed: {$e->getMessage()}");
                Log::error("Outbox relay failed for event {$event->id}: {$e->getMessage()}");
                break;
            }
        }

        $this->info("Published {$published}/{$events->count()} events.");

        return self::SUCCESS;
    }
}
