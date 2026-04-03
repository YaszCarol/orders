<?php

namespace App\EventBus;

use App\Events\DomainEvent;
use App\Models\OutboxEvent;

class OutboxEventBus implements EventBusInterface
{
    public function publish(DomainEvent $event): void
    {
        OutboxEvent::create([
            'aggregate_id' => $event->aggregateId(),
            'aggregate_type' => $event->aggregateType(),
            'event_type' => $event->eventType(),
            'routing_key' => $event->eventType(),
            'payload' => [
                'event_id' => $event->eventId(),
                'event_type' => $event->eventType(),
                'occurred_at' => $event->occurredAt()->format('c'),
                'payload' => $event->payload(),
            ],
        ]);
    }
}
