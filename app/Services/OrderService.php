<?php

namespace App\Services;

use App\EventBus\EventBusInterface;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Repositories\OrderRepositoryInterface;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private OrderRepositoryInterface $repository,
        private EventBusInterface $eventBus
    ) {}

    public function create(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $order = $this->repository->create($data);
            $this->eventBus->publish(new OrderCreated($order));

            return $order;
        });
    }
}
