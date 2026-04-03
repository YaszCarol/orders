<?php

namespace App\Repositories;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function create(array $data): Order
    {
        return Order::create($data);
    }

    public function findOrFail(string $id): Order
    {
        return Order::findOrFail($id);
    }

    public function find(string $id): ?Order
    {
        return Order::find($id);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Order::latest()->paginate($perPage);
    }

    public function updateClassification(Order $order, array $data): void
    {
        $order->update($data);
    }
}
