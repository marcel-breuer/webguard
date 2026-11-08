<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitoringStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;

/**
 * Class MonitoringResponse
 *
 * Represents a monitoring response result, tracking status and response time.
 *
 * @property string $id
 * @property string $monitoring_id
 * @property MonitoringStatus|null $status
 * @property float $response_time
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Monitoring $monitoring
 * @property-read User $user
 */
#[Fillable([
    'monitoring_id',
    'location_code',
    'status',
    'http_status_code',
    'response_time',
    'check_interval_seconds',
    'server_health_metrics',
    'server_health_report_id',
    'server_health_sampled_at',
    'vital_values',
])]
#[Table(name: 'monitoring_response_results', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class MonitoringResponse extends Model
{
    /** @use HasFactory<Factory<self>> */
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
            'status' => MonitoringStatus::class,
            'http_status_code' => 'integer',
            'response_time' => 'float',
            'check_interval_seconds' => 'integer',
            'server_health_metrics' => 'array',
            'server_health_sampled_at' => 'datetime',
            'vital_values' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
