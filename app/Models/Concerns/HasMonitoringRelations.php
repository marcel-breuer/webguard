<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\MonitoringDailyResult;
use App\Models\MonitoringDomainResult;
use App\Models\MonitoringGroup;
use App\Models\MonitoringNotification;
use App\Models\MonitoringNotificationPreference;
use App\Models\MonitoringPerformanceState;
use App\Models\MonitoringResponse;
use App\Models\MonitoringResponseArchived;
use App\Models\MonitoringSslResult;
use App\Models\StatusPageComponent;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Eloquent relationships of a monitoring.
 */
trait HasMonitoringRelations
{
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
}
