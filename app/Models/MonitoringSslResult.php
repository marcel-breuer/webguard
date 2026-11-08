<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;

/**
 * Class MonitoringSslResult
 *
 * Represents a monitoring SSL result, tracking SSL certificate validity.
 *
 * @property string $id
 * @property string $monitoring_id
 * @property Carbon $expires_at
 * @property bool $is_valid
 * @property string $issuer
 * @property Carbon $issued_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Monitoring $monitoring
 * @property-read User $user
 */
#[Fillable([
    'monitoring_id',
    'expires_at',
    'is_valid',
    'issuer',
    'issued_at',
])]
#[Table(name: 'monitoring_ssl_results', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class MonitoringSslResult extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;
    use HasUlids;

    /**
     * Get the monitoring instance that this result belongs to.
     *
     * @return BelongsTo<Monitoring, $this>
     */
    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }

    /**
     * Get the user associated with this monitoring result through monitoring.
     *
     * @return HasOneThrough<User, Monitoring, $this>
     */
    public function user(): HasOneThrough
    {
        return $this->hasOneThrough(
            User::class,
            Monitoring::class,
            'id',
            'id',
            'monitoring_id',
            'user_id'
        );
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_valid' => 'boolean',
            'issued_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
