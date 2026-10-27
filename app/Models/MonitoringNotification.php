<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use App\Models\Scopes\UserScope;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Appends(['created_at_for_humans', 'translated_message'])]
#[Fillable([
    'monitoring_id',
    'type',
    'message',
    'metadata',
    'expiry_notification_key',
    'read',
    'sent',
])]
#[Table(name: 'monitoring_notifications', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class MonitoringNotification extends Model
{
    use HasFactory;
    use HasUlids;

    public static function extractStatusChangeIdentifierFromMessage(string $message): string
    {
        $normalized = mb_strtolower(mb_trim($message));

        if (str_contains($normalized, 'down')) {
            return 'down';
        }

        if (str_contains($normalized, 'up')) {
            return 'up';
        }

        return 'unknown';
    }

    /**
     * @return BelongsTo<Monitoring, $this>
     */
    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }

    /**
     * @return HasMany<MonitoringNotificationState, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(MonitoringNotificationState::class);
    }

    public function statusChangeIdentifier(bool $maintenanceActive = false): string
    {
        if ($maintenanceActive) {
            return 'maintenance';
        }

        return self::extractStatusChangeIdentifierFromMessage($this->message);
    }

    public function statusChangeKey(bool $maintenanceActive = false): string
    {
        return 'notifications.status_change.' . $this->statusChangeIdentifier($maintenanceActive);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new UserScope());

        static::created(function (MonitoringNotification $monitoringNotification): void {
            /** @var Monitoring|null $monitoring */
            $monitoring = Monitoring::query()
                ->withoutGlobalScopes()
                ->with(['user', 'team.users'])
                ->find($monitoringNotification->monitoring_id);

            if (! $monitoring) {
                return;
            }

            $users = $monitoring->team_id !== null
                ? ($monitoring->team?->users ?? collect())
                : collect([$monitoring->user])->filter();

            foreach ($users as $user) {
                MonitoringNotificationState::query()->firstOrCreate([
                    'monitoring_notification_id' => $monitoringNotification->id,
                    'user_id' => $user->id,
                ], [
                    'read_at' => $monitoringNotification->read ? now() : null,
                    'sent_at' => $monitoringNotification->sent ? now() : null,
                ]);
            }
        });
    }

    /**
     * Scope notifications by type.
     */
    #[Scope]
    protected function ofType(Builder $builder, NotificationType|string $type): Builder
    {
        $value = $type instanceof NotificationType ? $type->value : $type;

        return $builder->where($builder->getModel()->qualifyColumn('type'), $value);
    }

    /**
     * Scope notifications to status change entries.
     */
    #[Scope]
    protected function statusChange(Builder $builder): Builder
    {
        return $builder->ofType(NotificationType::STATUS_CHANGE);
    }

    /**
     * Scope notifications to SSL expiry entries.
     */
    #[Scope]
    protected function sslExpiry(Builder $builder): Builder
    {
        return $builder->ofType(NotificationType::SSL_EXPIRY);
    }

    /**
     * Scope notifications to domain expiry entries.
     */
    #[Scope]
    protected function domainExpiry(Builder $builder): Builder
    {
        return $builder->ofType(NotificationType::DOMAIN_EXPIRY);
    }

    /**
     * Scope notifications to performance entries.
     */
    #[Scope]
    protected function performance(Builder $builder): Builder
    {
        return $builder->ofType(NotificationType::PERFORMANCE);
    }

    /**
     * Scope notifications to read entries.
     */
    #[Scope]
    protected function read(Builder $builder): Builder
    {
        if (auth()->check()) {
            return $builder->whereHas('states', function (Builder $builder): void {
                $builder->where('user_id', auth()->id())
                    ->whereNotNull('read_at');
            });
        }

        return $builder->where('read', true);
    }

    /**
     * Scope notifications to unread entries.
     */
    #[Scope]
    protected function unread(Builder $builder): Builder
    {
        if (auth()->check()) {
            return $builder->whereHas('states', function (Builder $builder): void {
                $builder->where('user_id', auth()->id())
                    ->whereNull('read_at');
            });
        }

        return $builder->where('read', false);
    }

    /**
     * Get the created_at attribute formatted for display.
     */
    protected function createdAtForHumans(): Attribute
    {
        return Attribute::make(get: function () {
            return $this->created_at->locale(app()->getLocale())->isoFormat('L LT');
        });
    }

    /**
     * Get the translated message attribute.
     */
    protected function translatedMessage(): Attribute
    {
        return Attribute::make(get: function () {
            if ($this->type === NotificationType::STATUS_CHANGE) {
                $status = self::extractStatusChangeIdentifierFromMessage($this->message);

                if (! in_array($status, ['up', 'down'], true)) {
                    return $this->message;
                }

                return __('notifications.status_messages.' . $status, ['name' => $this->monitoring->name]);
            }

            if ($this->type === NotificationType::SSL_EXPIRY) {
                return match ($this->message) {
                    'SSL_EXPIRED' => __('notifications.ssl_messages.expired', ['name' => $this->monitoring->name]),
                    'SSL_EXPIRING' => __('notifications.ssl_messages.expiring', ['name' => $this->monitoring->name]),
                    default => $this->message,
                };
            }

            if ($this->type === NotificationType::DOMAIN_EXPIRY) {
                return match ($this->message) {
                    'DOMAIN_EXPIRED' => __('notifications.domain_messages.expired', ['name' => $this->monitoring->name]),
                    'DOMAIN_EXPIRING' => __('notifications.domain_messages.expiring', ['name' => $this->monitoring->name]),
                    default => $this->message,
                };
            }

            if ($this->type === NotificationType::PERFORMANCE && ($this->metadata['alert_kind'] ?? null) === 'historical_response_time') {
                $messageType = $this->message === 'DEGRADED' ? 'degraded' : 'recovered';

                return __('notifications.performance_messages.' . $messageType, [
                    'name' => $this->monitoring?->name ?? '',
                    'location' => $this->metadata['location_code'] ?? 'default',
                    'current_ms' => $this->metadata['response_time_ms'] ?? 0,
                    'baseline_ms' => $this->metadata['baseline_median_ms'] ?? 0,
                    'increase_percent' => $this->metadata['increase_percent'] ?? 0,
                ]);
            }

            // For other types, return the original message
            return $this->message;
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
            'type' => NotificationType::class,
            'metadata' => 'array',
            'read' => 'boolean',
            'sent' => 'boolean',
        ];
    }
}
