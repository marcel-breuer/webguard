<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HttpMethod;
use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringType;
use App\Models\Concerns\HasMonitoringMaintenance;
use App\Models\Concerns\HasMonitoringOwnership;
use App\Models\Concerns\HasMonitoringRelations;
use Database\Factories\MonitoringFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Class Monitoring
 *
 * Represents a monitoring instance with type, target, keyword or port,
 * associated with a user and having many results.
 *
 * @property bool|null $has_enabled_maintenance_windows
 **/
#[Fillable([
    'user_id',
    'team_id',
    'created_by_user_id',
    'name',
    'type',
    'target',
    'port',
    'keyword',
    'dns_record_type',
    'dns_expected_values',
    'status',
    'timeout',
    'check_interval_minutes',
    'http_method',
    'expected_http_statuses',
    'http_headers',
    'http_body',
    'auth_username',
    'auth_password',
    'public_label_enabled',
    'preferred_location',
    'preferred_locations',
    'notification_on_failure',
    'notification_channels',
    'failure_confirmation_threshold',
    'response_time_threshold_ms',
    'response_time_confirmation_threshold',
    'response_time_degradation_percent',
    'ssl_expiry_warning_days',
    'deleted_at',
    'maintenance_from',
    'maintenance_until',
    'heartbeat_token',
    'heartbeat_interval_minutes',
    'heartbeat_grace_minutes',
    'heartbeat_last_ping_at',
    'server_health_token',
    'server_health_last_reported_at',
    'server_health_cpu_threshold_percent',
    'server_health_ram_threshold_percent',
    'server_health_storage_threshold_percent',
    'server_health_load_threshold_per_cpu',
    'server_health_service_response_time_threshold_ms',
    'server_health_report_interval_minutes',
    'server_health_grace_minutes',
])]
#[Table(name: 'monitorings', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class Monitoring extends Model
{
    /** @use HasFactory<MonitoringFactory> */
    use HasFactory;

    use HasMonitoringMaintenance;
    use HasMonitoringOwnership;
    use HasMonitoringRelations;
    use HasUlids;
    use LogsActivity;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    private array $activityLogOriginalAttributes = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('monitoring')
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly([
                'heartbeat_last_ping_at',
                'server_health_last_reported_at',
                'updated_at',
            ])
            ->setDescriptionForEvent(fn (string $eventName): string => 'monitoring_' . $eventName);
    }

    /**
     * Determine if the monitoring is active.
     */
    public function isActive(): bool
    {
        return $this->status === MonitoringLifecycleStatus::ACTIVE;
    }

    /**
     * Determine if the monitoring is paused.
     */
    public function isPaused(): bool
    {
        return $this->status === MonitoringLifecycleStatus::PAUSED;
    }

    public function isHeartbeat(): bool
    {
        return $this->type === MonitoringType::HEARTBEAT;
    }

    public function isServerHealth(): bool
    {
        return $this->type === MonitoringType::SERVER_HEALTH;
    }

    /**
     * @return list<string>
     */
    public function preferredLocationCodes(): array
    {
        $locations = is_array($this->preferred_locations) ? $this->preferred_locations : [];

        if (is_string($this->preferred_location) && $this->preferred_location !== '') {
            array_unshift($locations, $this->preferred_location);
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $location): string => (string) $location, $locations),
            static fn (string $location): bool => $location !== ''
        )));
    }

    /**
     * Include recurring maintenance presence in route-bound monitoring queries.
     *
     * @param  mixed  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->withMaintenanceWindowState();
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogOriginalAttributes(): array
    {
        return $this->activityLogOriginalAttributes;
    }

    public function forgetActivityLogOriginalAttributes(): void
    {
        $this->activityLogOriginalAttributes = [];
    }

    /**
     * Apply the global scope to ensure all queries are restricted to the authenticated user.
     */
    #[Override]
    protected static function booted(): void
    {
        parent::boot();

        static::updating(function (self $monitoring): void {
            $monitoring->activityLogOriginalAttributes = $monitoring->getRawOriginal();
        });

        static::addGlobalScope('user', function (Builder $builder): void {
            if (Auth::check()) {
                $user = Auth::user();

                $builder->where(function (Builder $builder) use ($user): void {
                    $builder->where('user_id', $user->id)
                        ->orWhereHas('team.memberships', function (Builder $builder) use ($user): void {
                            $builder->where('user_id', $user->id);
                        });
                });
            }
        });
    }

    /**
     * Scope a query to only include active monitorings.
     *
     * @param  Builder<Monitoring>  $builder
     * @return Builder<Monitoring>
     */
    #[Scope]
    protected function active(Builder $builder): Builder
    {
        return $builder->where('status', MonitoringLifecycleStatus::ACTIVE);
    }

    /**
     * Scope a query to only include paused monitorings.
     *
     * @param  Builder<Monitoring>  $builder
     * @return Builder<Monitoring>
     */
    #[Scope]
    protected function paused(Builder $builder): Builder
    {
        return $builder->where('status', MonitoringLifecycleStatus::PAUSED);
    }

    /**
     * Scope a query to monitorings assigned to the given server instance code.
     *
     * @param  Builder<Monitoring>  $builder
     * @return Builder<Monitoring>
     */
    #[Scope]
    protected function assignedToLocation(Builder $builder, string $location): Builder
    {
        return $builder->where(function (Builder $builder) use ($location): void {
            $builder->where('preferred_location', $location)
                ->orWhereJsonContains('preferred_locations', $location);
        });
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MonitoringType::class,
            'status' => MonitoringLifecycleStatus::class,
            'timeout' => 'integer',
            'check_interval_minutes' => 'integer',
            'http_method' => HttpMethod::class,
            'expected_http_statuses' => 'string',
            'dns_expected_values' => 'array',
            'http_headers' => 'array',
            'public_label_enabled' => 'boolean',
            'notification_on_failure' => 'boolean',
            'preferred_location' => 'string',
            'preferred_locations' => 'array',
            'heartbeat_interval_minutes' => 'integer',
            'heartbeat_grace_minutes' => 'integer',
            'notification_channels' => 'array',
            'failure_confirmation_threshold' => 'integer',
            'response_time_threshold_ms' => 'integer',
            'response_time_confirmation_threshold' => 'integer',
            'response_time_degradation_percent' => 'integer',
            'ssl_expiry_warning_days' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'maintenance_from' => 'datetime',
            'maintenance_until' => 'datetime',
            'heartbeat_last_ping_at' => 'datetime',
            'server_health_last_reported_at' => 'datetime',
            'server_health_cpu_threshold_percent' => 'float',
            'server_health_ram_threshold_percent' => 'float',
            'server_health_storage_threshold_percent' => 'float',
            'server_health_load_threshold_per_cpu' => 'float',
            'server_health_service_response_time_threshold_ms' => 'integer',
            'server_health_report_interval_minutes' => 'integer',
            'server_health_grace_minutes' => 'integer',
            'has_enabled_maintenance_windows' => 'boolean',
        ];
    }
}
