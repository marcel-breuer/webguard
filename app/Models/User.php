<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TeamRole;
use App\Enums\UserRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Class User
 *
 * Represents a registered user of the application, including authentication and related monitorings.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property UserRole $role
 * @property Carbon|null $terms_accepted_at
 * @property Carbon|null $privacy_accepted_at
 * @property string|null $package_id
 * @property string|null $format_locale
 * @property array<string, mixed>|null $notification_channels
 * @property Carbon|null $notification_channels_hint_seen_at
 * @property bool $monitoring_digest_enabled
 * @property string $monitoring_digest_frequency
 * @property bool $unread_notifications_reminder_enabled
 * @property string $unread_notifications_reminder_frequency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read Collection<int, Monitoring> $monitorings
 * @property-read int|null $monitorings_count
 * @property-read Collection<int, MobilePushDevice> $mobilePushDevices
 * @property-read int|null $mobile_push_devices_count
 * @property-read Collection<int, MonitoringGroup> $monitoringGroups
 * @property-read int|null $monitoring_groups_count
 * @property-read Collection<int, ApiLog> $apiLogs
 * @property-read int|null $api_logs_count
 * @property-read Package|null $package
 *
 * @method static Builder|static newModelQuery()
 * @method static Builder|static newQuery()
 * @method static Builder|static query()
 */
#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'terms_accepted_at',
    'privacy_accepted_at',
    'package_id',
    'locale',
    'format_locale',
    'theme',
    'avatar',
    'notification_channels',
    'notification_channels_hint_seen_at',
    'monitoring_digest_enabled',
    'monitoring_digest_frequency',
    'unread_notifications_reminder_enabled',
    'unread_notifications_reminder_frequency',
])]
#[Hidden([
    'password',
    'remember_token',
])]
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use HasUlids;
    use LogsActivity;
    use Notifiable;

    /**
     * Get all monitorings that belong to the user.
     *
     * @return HasMany<Monitoring, $this>
     */
    public function monitorings(): HasMany
    {
        return $this->hasMany(Monitoring::class);
    }

    /**
     * @return HasMany<MobilePushDevice, $this>
     */
    public function mobilePushDevices(): HasMany
    {
        return $this->hasMany(MobilePushDevice::class);
    }

    /**
     * Get all monitoring groups that belong to the user.
     *
     * @return HasMany<MonitoringGroup, $this>
     */
    public function monitoringGroups(): HasMany
    {
        return $this->hasMany(MonitoringGroup::class);
    }

    /**
     * @return HasMany<TeamMembership, $this>
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_memberships')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function administeredTeams(): BelongsToMany
    {
        return $this->teams()->wherePivot('role', TeamRole::ADMIN->value);
    }

    /**
     * @return HasMany<MonitoringNotificationPreference, $this>
     */
    public function monitoringNotificationPreferences(): HasMany
    {
        return $this->hasMany(MonitoringNotificationPreference::class);
    }

    /**
     * @return HasMany<MonitoringNotificationState, $this>
     */
    public function monitoringNotificationStates(): HasMany
    {
        return $this->hasMany(MonitoringNotificationState::class);
    }

    /**
     * @return HasMany<StatusPage, $this>
     */
    public function statusPages(): HasMany
    {
        return $this->hasMany(StatusPage::class);
    }

    /**
     * Get the API logs associated with the user.
     *
     * @return HasMany<ApiLog, $this>
     */
    public function apiLogs(): HasMany
    {
        return $this->hasMany(ApiLog::class);
    }

    /**
     * Get the package associated with the user.
     *
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Determine if the user has admin role.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    /**
     * Determine if the user has regular role.
     */
    public function isMember(): bool
    {
        return $this->role === UserRole::REGULAR;
    }

    /**
     * Determine if the user has demo role.
     */
    public function isDemo(): bool
    {
        return $this->role === UserRole::DEMO;
    }

    /**
     * @deprecated Use isDemo().
     */
    public function isGuest(): bool
    {
        return $this->isDemo();
    }

    /**
     * Get all of the notifications for the user.
     *
     * @return HasManyThrough<MonitoringNotification, Monitoring, $this>
     */
    public function notifications(): HasManyThrough
    {
        return $this->hasManyThrough(MonitoringNotification::class, Monitoring::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly([
                'notification_channels_hint_seen_at',
                'updated_at',
            ])
            ->setDescriptionForEvent(fn (string $eventName): string => 'user_' . $eventName);
    }

    /**
     * Get all of the unread notifications for the user.
     *
     * @return HasManyThrough<MonitoringNotification, Monitoring, $this>
     */
    public function unreadNotifications(): HasManyThrough
    {
        return $this->hasManyThrough(MonitoringNotification::class, Monitoring::class)->unread();
    }

    public function hasEnabledNotificationChannels(): bool
    {
        return $this->enabledNotificationChannelKeys() !== [];
    }

    /**
     * @return list<string>
     */
    public function enabledNotificationChannelKeys(): array
    {
        $channels = is_array($this->notification_channels) ? $this->notification_channels : [];
        $enabledChannels = [];

        foreach ($channels as $channel => $config) {
            if (! is_array($config)) {
                continue;
            }

            if ((bool) ($config['enabled'] ?? false)) {
                $enabledChannels[] = (string) $channel;
            }
        }

        return $enabledChannels;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'theme' => 'string',
            'notification_channels' => 'array',
            'notification_channels_hint_seen_at' => 'datetime',
            'monitoring_digest_enabled' => 'boolean',
            'monitoring_digest_frequency' => 'string',
            'unread_notifications_reminder_enabled' => 'boolean',
            'unread_notifications_reminder_frequency' => 'string',
        ];
    }
}
