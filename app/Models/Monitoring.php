<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HttpMethod;
use App\Enums\MonitoringLifecycleStatus;
use App\Enums\MonitoringType;
use App\Enums\TeamRole;
use App\Services\MaintenanceWindowService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    use HasFactory;
    use HasUlids;
    use LogsActivity;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    private array $activityLogOriginalAttributes = [];

    /**
     * Get the user that owns the monitoring.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<MonitoringResponse, $this>
     */
    public function responseResults(): HasMany
    {
        return $this->hasMany(MonitoringResponse::class, 'monitoring_id');
    }

    /**
     * @return HasOne<MonitoringResponse, $this>
     */
    public function latestResponseResult(): HasOne
    {
        return $this->hasOne(MonitoringResponse::class, 'monitoring_id')->latestOfMany();
    }

    /**
     * @return HasMany<MonitoringDailyResult, $this>
     */
    public function dailyResults(): HasMany
    {
        return $this->hasMany(MonitoringDailyResult::class, 'monitoring_id');
    }

    /**
     * @return HasOne<MonitoringSslResult, $this>
     */
    public function sslResult(): HasOne
    {
        return $this->hasOne(MonitoringSslResult::class, 'monitoring_id');
    }

    /**
     * @return HasOne<MonitoringDomainResult, $this>
     */
    public function domainResult(): HasOne
    {
        return $this->hasOne(MonitoringDomainResult::class, 'monitoring_id');
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'monitoring_id');
    }

    /**
     * @return HasMany<MaintenanceWindow, $this>
     */
    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    /**
     * @return HasOne<Incident, $this>
     */
    public function latestIncident(): HasOne
    {
        return $this->hasOne(Incident::class, 'monitoring_id')->latestOfMany();
    }

    /**
     * @return HasOne<MonitoringPerformanceState, $this>
     */
    public function performanceState(): HasOne
    {
        return $this->hasOne(MonitoringPerformanceState::class);
    }

    /**
     * @return HasOne<MonitoringNotification, $this>
     */
    public function latestStatusChangeNotification(): HasOne
    {
        return $this->hasOne(MonitoringNotification::class, 'monitoring_id')->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            function (Builder $builder): void {
                $builder->statusChange();
            }
        );
    }

    /**
     * @return HasOne<MonitoringNotification, $this>
     */
    public function latestUnreadStatusChangeNotification(): HasOne
    {
        return $this->hasOne(MonitoringNotification::class, 'monitoring_id')->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            function (Builder $builder): void {
                $builder->statusChange()->unread();
            }
        );
    }

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
     * @return HasMany<MonitoringNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(MonitoringNotification::class);
    }

    /**
     * @return HasMany<MonitoringNotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(MonitoringNotificationPreference::class);
    }

    /**
     * @return HasMany<StatusPageSubscriber, $this>
     */
    public function statusPageSubscribers(): HasMany
    {
        return $this->hasMany(StatusPageSubscriber::class);
    }

    /**
     * @return HasMany<MonitoringResponseArchived, $this>
     */
    public function archivedResponseResults(): HasMany
    {
        return $this->hasMany(MonitoringResponseArchived::class, 'monitoring_id');
    }

    /**
     * @return BelongsToMany<StatusPageComponent, $this>
     */
    public function statusPageComponents(): BelongsToMany
    {
        return $this->belongsToMany(StatusPageComponent::class, 'status_page_component_monitoring')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<MonitoringGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(MonitoringGroup::class, 'monitoring_group_monitoring')
            ->withTimestamps();
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

    public function isTeamOwned(): bool
    {
        return $this->team_id !== null;
    }

    public function isPrivateOwned(): bool
    {
        return $this->user_id !== null && $this->team_id === null;
    }

    public function isVisibleTo(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        if ($this->team_id === null) {
            return false;
        }

        return $this->team()
            ->whereHas('memberships', function (Builder $builder) use ($user): void {
                $builder->where('user_id', $user->id);
            })
            ->exists();
    }

    public function isManageableBy(User $user): bool
    {
        if ($this->user_id === $user->id && $this->team_id === null) {
            return true;
        }

        if ($this->team_id === null) {
            return false;
        }

        return $this->team()
            ->whereHas('memberships', function (Builder $builder) use ($user): void {
                $builder->where('user_id', $user->id)
                    ->where('role', TeamRole::ADMIN);
            })
            ->exists();
    }

    /**
     * Determine if the monitoring is currently under maintenance.
     */
    public function isUnderMaintenance(): bool
    {
        if ($this->maintenance_from && is_null($this->maintenance_until)) {
            if ($this->maintenance_from->isPast()) {
                return true;
            }

            return resolve(MaintenanceWindowService::class)->isUnderMaintenance($this);
        }

        if ($this->maintenance_from && $this->maintenance_until) {
            if (now()->between($this->maintenance_from, $this->maintenance_until)) {
                return true;
            }

            return resolve(MaintenanceWindowService::class)->isUnderMaintenance($this);
        }

        return resolve(MaintenanceWindowService::class)->isUnderMaintenance($this);
    }

    /**
     * @return array{starts_at: CarbonInterface, ends_at: CarbonInterface|null, active: bool, recurring: bool}|null
     */
    public function currentOrUpcomingMaintenanceWindow(): ?array
    {
        if ($this->maintenance_from && (! $this->maintenance_until || $this->maintenance_until->isFuture())) {
            return [
                'starts_at' => $this->maintenance_from,
                'ends_at' => $this->maintenance_until,
                'active' => $this->isUnderMaintenanceWithoutRecurringWindow(),
                'recurring' => false,
            ];
        }

        return resolve(MaintenanceWindowService::class)->currentOrUpcoming($this);
    }

    public function hasUpcomingMaintenance(): bool
    {
        $window = $this->currentOrUpcomingMaintenanceWindow();

        return $window !== null && ! $window['active'];
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
     * Add the recurring maintenance presence flag used by high-throughput payloads.
     */
    #[Scope]
    protected function withMaintenanceWindowState(Builder $builder): Builder
    {
        $monitoringTable = $builder->getModel()->getTable();

        $maintenanceWindowQuery = MaintenanceWindow::query()
            ->selectRaw('1')
            ->where('enabled', true)
            ->where(function (Builder $builder) use ($monitoringTable): void {
                $builder
                    ->whereColumn('maintenance_windows.monitoring_id', $monitoringTable . '.id')
                    ->orWhereExists(function (\Illuminate\Database\Query\Builder $builder) use ($monitoringTable): void {
                        $builder
                            ->selectRaw('1')
                            ->from('monitoring_group_monitoring')
                            ->whereColumn('monitoring_group_monitoring.monitoring_id', $monitoringTable . '.id')
                            ->whereColumn(
                                'monitoring_group_monitoring.monitoring_group_id',
                                'maintenance_windows.monitoring_group_id'
                            );
                    });
            });

        return $builder->addSelect([
            'has_enabled_maintenance_windows' => $maintenanceWindowQuery,
        ]);
    }

    /**
     * Scope a query to only include active monitorings.
     */
    #[Scope]
    protected function active(Builder $builder): Builder
    {
        return $builder->where('status', MonitoringLifecycleStatus::ACTIVE);
    }

    /**
     * Scope a query to only include paused monitorings.
     */
    #[Scope]
    protected function paused(Builder $builder): Builder
    {
        return $builder->where('status', MonitoringLifecycleStatus::PAUSED);
    }

    #[Scope]
    protected function visibleTo(Builder $builder, User $user): Builder
    {
        return $builder->where(function (Builder $builder) use ($user): void {
            $builder->where('user_id', $user->id)
                ->orWhereHas('team.memberships', function (Builder $builder) use ($user): void {
                    $builder->where('user_id', $user->id);
                });
        });
    }

    #[Scope]
    protected function manageableBy(Builder $builder, User $user): Builder
    {
        return $builder->where(function (Builder $builder) use ($user): void {
            $builder->where(function (Builder $builder) use ($user): void {
                $builder->where('user_id', $user->id)
                    ->whereNull('team_id');
            })
                ->orWhereHas('team.memberships', function (Builder $builder) use ($user): void {
                    $builder->where('user_id', $user->id)
                        ->where('role', TeamRole::ADMIN);
                });
        });
    }

    #[Scope]
    protected function privateOwnedBy(Builder $builder, User $user): Builder
    {
        return $builder->where('user_id', $user->id)
            ->whereNull('team_id');
    }

    /**
     * Scope a query to monitorings assigned to the given server instance code.
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

    private function isUnderMaintenanceWithoutRecurringWindow(): bool
    {
        if (! $this->maintenance_from) {
            return false;
        }

        return $this->maintenance_until === null
            ? $this->maintenance_from->isPast()
            : now()->between($this->maintenance_from, $this->maintenance_until);
    }
}
