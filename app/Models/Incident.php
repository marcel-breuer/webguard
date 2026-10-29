<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentContributingCategory;
use App\Enums\IncidentCustomerImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentType;
use App\Enums\RegionalConsensusStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Class Incident
 *
 * Represents a monitoring incident, tracking downtime and uptime.
 *
 * @property string $id
 * @property string $monitoring_id
 * @property Carbon $down_at
 * @property Carbon|null $up_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Monitoring $monitoring
 */
#[Fillable([
    'monitoring_id',
    'consensus_status',
    'affected_locations',
    'consensus_snapshot',
    'problem_description',
    'resolution_description',
    'incident_type',
    'severity',
    'affected_service',
    'customer_impact',
    'contributing_category',
    'down_at',
    'up_at',

])]
class Incident extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * Get the monitoring that the incident belongs to.
     *
     * @return BelongsTo<Monitoring, $this>
     */
    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }

    /**
     * @return HasMany<IncidentUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->latest();
    }

    /**
     * @return HasMany<IncidentFollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(IncidentFollowUp::class)->latest();
    }

    /**
     * @return HasMany<IncidentTimelineEvent, $this>
     */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(IncidentTimelineEvent::class)->oldest('occurred_at');
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consensus_status' => RegionalConsensusStatus::class,
            'affected_locations' => 'array',
            'consensus_snapshot' => 'array',
            'problem_description' => 'string',
            'resolution_description' => 'string',
            'incident_type' => IncidentType::class,
            'severity' => IncidentSeverity::class,
            'customer_impact' => IncidentCustomerImpact::class,
            'contributing_category' => IncidentContributingCategory::class,
            'down_at' => 'datetime',
            'up_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
