<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'status_page_subscription_id',
    'fingerprint',
    'sent_at',
    'payload',
    'attempts',
    'available_at',
    'queued_at',
    'cancelled_at',
])]
#[Table(name: 'status_page_maintenance_deliveries', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class StatusPageMaintenanceDelivery extends Model
{
    use HasFactory;
    use HasUlids;

    public const int MAX_ATTEMPTS = 5;

    public const int LEASE_MINUTES = 5;

    /**
     * @return BelongsTo<StatusPageSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(StatusPageSubscription::class, 'status_page_subscription_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ready(Builder $query): void
    {
        $query->whereNotNull('payload')
            ->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $builder) => $builder->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'queued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
