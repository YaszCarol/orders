<?php

namespace App\Providers;

use App\EventBus\EventBusInterface;
use App\EventBus\OutboxEventBus;
use App\Repositories\EloquentOrderRepository;
use App\Repositories\OrderRepositoryInterface;
use App\Services\RabbitMQService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RabbitMQService::class);
        $this->app->singleton(EventBusInterface::class, OutboxEventBus::class);
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
