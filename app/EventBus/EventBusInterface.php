<?php

namespace App\EventBus;

use App\Events\DomainEvent;

interface EventBusInterface
{
    public function publish(DomainEvent $event): void;
}
