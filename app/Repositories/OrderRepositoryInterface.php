<?php

namespace App\Repositories;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrderRepositoryInterface
{
    public function create(array $data): Order;

    public function findOrFail(string $id): Order;

    public function find(string $id): ?Order;

    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function updateClassification(Order $order, array $data): void;
}
