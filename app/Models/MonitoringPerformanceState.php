<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitoringPerformanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monitoring_id', 'status', 'consecutive_breaches', 'degraded_at', 'recovered_at'])]
#[Table(name: 'monitoring_performance_states', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class MonitoringPerformanceState extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;
    use HasUlids;

    /** @return BelongsTo<Monitoring, $this> */
    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MonitoringPerformanceStatus::class,
            'consecutive_breaches' => 'integer',
            'degraded_at' => 'datetime',
            'recovered_at' => 'datetime',
        ];
    }
}
