<?php

namespace App\Providers;

use App\EventBus\EventBusInterface;
use App\EventBus\OutboxEventBus;
use App\Services\RabbitMQService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RabbitMQService::class);
        $this->app->singleton(EventBusInterface::class, OutboxEventBus::class);
    }

    public function boot(): void
    {
        //
    }
}
