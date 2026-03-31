<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\RiskLevel;
use App\Events\OrderCreated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['description', 'amount', 'status', 'risk_level', 'ai_reasoning'])]
class Order extends Model
{
    use HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'risk_level' => RiskLevel::class,
            'amount' => 'decimal:2',
        ];
    }

    protected $dispatchesEvents = [
        'created' => OrderCreated::class,
    ];
}
