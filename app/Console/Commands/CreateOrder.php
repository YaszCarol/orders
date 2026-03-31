<?php

namespace App\Console\Commands;

use App\Events\OrderCreated;
use App\Models\Order;
use Illuminate\Console\Command;

class CreateOrder extends Command
{
    protected $signature = 'orders:create
                            {--description= : Order description}
                            {--amount= : Order amount}';

    protected $description = 'Create a test order and dispatch classification';

    public function handle(): int
    {
        $description = $this->option('description')
            ?? $this->ask('Order description');

        $amount = $this->option('amount')
            ?? $this->ask('Order amount (R$)');

        $order = Order::create([
            'description' => $description,
            'amount' => (float) $amount,
        ]);

        OrderCreated::dispatch($order);

        $this->info("Order created: {$order->id}");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $order->id],
                ['Description', $order->description],
                ['Amount', 'R$ ' . number_format($order->amount, 2, ',', '.')],
                ['Status', 'pending (awaiting AI classification)'],
            ]
        );

        return self::SUCCESS;
    }
}
