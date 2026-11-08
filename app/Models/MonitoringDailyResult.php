<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Class MonitoringDailyResult
 *
 * @property int $id
 * @property string $monitoring_id
 * @property string $date
 * @property int $uptime_total
 * @property int $downtime_total
 * @property int $unknown_total
 * @property float $uptime_percentage
 * @property float $downtime_percentage
 * @property float $unknown_percentage
 * @property int $uptime_minutes
 * @property int $downtime_minutes
 * @property int $unknown_minutes
 * @property float|null $avg_response_time
 * @property int|null $min_response_time
 * @property int|null $max_response_time
 * @property int $incidents_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Monitoring $monitoring
 *
 * This model represents the aggregated daily monitoring results.
 * Raw monitoring data is processed daily and summarized into this table
 * to optimize performance for historical data retrieval and reporting.
 */
#[Fillable([
    'monitoring_id',
    'date',
    'uptime_total',
    'downtime_total',
    'unknown_total',
    'uptime_percentage',
    'downtime_percentage',
    'unknown_percentage',
    'uptime_minutes',
    'downtime_minutes',
    'unknown_minutes',
    'avg_response_time',
    'min_response_time',
    'max_response_time',
    'incidents_count',
])]
class MonitoringDailyResult extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;

    /**
     * @return BelongsTo<Monitoring, $this>
     */
    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'uptime_percentage' => 'float',
            'downtime_percentage' => 'float',
            'unknown_percentage' => 'float',
            'uptime_minutes' => 'integer',
            'downtime_minutes' => 'integer',
            'unknown_minutes' => 'integer',
        ];
    }
}
