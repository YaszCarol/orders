<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;

class CreateOrder extends Command
{
    protected $signature = 'orders:create
                            {--description= : Order description}
                            {--amount= : Order amount}';

    protected $description = 'Create a test order and publish OrderCreated event';

    public function handle(OrderService $orderService): int
    {
        $description = $this->option('description')
            ?? $this->ask('Order description');

        $amount = $this->option('amount')
            ?? $this->ask('Order amount (R$)');

        $order = $orderService->create([
            'description' => $description,
            'amount' => (float) $amount,
        ]);

        $this->info("Order created: {$order->id}");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $order->id],
                ['Description', $order->description],
                ['Amount', 'R$ ' . number_format($order->amount, 2, ',', '.')],
                ['Status', 'pending (event in outbox)'],
            ]
        );

        return self::SUCCESS;
    }
}
