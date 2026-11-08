<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'id',
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
    'created_at',
    'updated_at',
])]
#[Table(name: 'monitoring_response_archived', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class MonitoringResponseArchived extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /**
     * Get the monitoring that owns the archived response.
     *
     * @return BelongsTo<Monitoring, $this>
     */
    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
