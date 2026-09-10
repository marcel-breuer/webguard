<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationType;
use App\Models\Monitoring;
use App\Models\MonitoringNotification;
use App\Models\MonitoringNotificationState;
use App\Models\MonitoringResponse;
use App\Models\NotificationChannelDelivery;
use App\Models\User;
use App\Support\MonitoringStatusMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Throwable;

class NotificationBoardService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function mobileEntries(User $user, ?string $cursor, int $limit, ?string $eventType, bool $showRead): Collection
    {
        $builder = MonitoringNotification::query()
            ->withoutGlobalScopes()
            ->select('monitoring_notifications.*', 'monitoring_notification_states.read_at as user_read_at')
            ->join('monitoring_notification_states', 'monitoring_notification_states.monitoring_notification_id', '=', 'monitoring_notifications.id')
            ->join('monitorings', 'monitoring_notifications.monitoring_id', '=', 'monitorings.id')
            ->where('monitoring_notification_states.user_id', $user->id)
            ->whereNull('monitorings.deleted_at')
            ->selectSub(
                NotificationChannelDelivery::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('notification_channel_deliveries.monitoring_notification_id', 'monitoring_notifications.id')
                    ->where('notification_channel_deliveries.user_id', $user->id)
                    ->where('notification_channel_deliveries.status', NotificationDeliveryStatus::FAILED),
                'failed_delivery_count',
            )
            ->where(function (Builder $builder) use ($user): void {
                $builder->where('monitorings.user_id', $user->id)
                    ->orWhereExists(function (QueryBuilder $queryBuilder) use ($user): void {
                        $queryBuilder->selectRaw('1')->from('team_memberships')
                            ->whereColumn('team_memberships.team_id', 'monitorings.team_id')
                            ->where('team_memberships.user_id', $user->id);
                    });
            })
            ->when($eventType !== null, fn (Builder $builder) => $this->filterMobileEntriesByEventType($builder, $eventType, $user))
            ->when(! $showRead, fn (Builder $builder) => $builder->whereNull('monitoring_notification_states.read_at'))
            ->with('monitoring:id,name,target')
            ->latest('monitoring_notifications.created_at')
            ->orderByDesc('monitoring_notifications.id')
            ->limit($limit + 1);

        if ($cursor !== null) {
            [$createdAt, $id] = $this->decodeCursor($cursor);
            $builder->where(function (Builder $cursorBuilder) use ($createdAt, $id): void {
                $cursorBuilder->where('monitoring_notifications.created_at', '<', $createdAt)
                    ->orWhere(function (Builder $sameTimestampBuilder) use ($createdAt, $id): void {
                        $sameTimestampBuilder->where('monitoring_notifications.created_at', $createdAt)
                            ->where('monitoring_notifications.id', '<', $id);
                    });
            });
        }

        return $builder->get()->map(function (MonitoringNotification $monitoringNotification): array {
            $eventType = $this->mobileEventType($monitoringNotification);
            $severity = match ($eventType) {
                'incident', 'ssl_expired', 'domain_expired' => 'critical',
                'ssl_expiring', 'domain_expiring', 'performance_degraded', 'delivery_failure' => 'warning',
                default => 'info',
            };

            return [
                'id' => $monitoringNotification->id,
                'event_type' => $eventType,
                'severity' => $severity,
                'message' => $monitoringNotification->message,
                'occurred_at' => $monitoringNotification->created_at?->toIso8601String(),
                'read' => $monitoringNotification->user_read_at !== null,
                'delivery_status' => (int) $monitoringNotification->failed_delivery_count > 0 ? 'failed' : 'unknown',
                'monitoring' => [
                    'id' => $monitoringNotification->monitoring->id,
                    'name' => $monitoringNotification->monitoring->name,
                    'target' => $monitoringNotification->monitoring->target,
                ],
                'cursor' => $this->encodeCursor($monitoringNotification),
            ];
        });
    }

    /**
     * @return list<string>
     */
    public function markRead(User $user, MonitoringNotification $monitoringNotification): array
    {
        $monitoring = Monitoring::query()->withoutGlobalScopes()->find($monitoringNotification->monitoring_id);
        abort_unless($monitoring instanceof Monitoring && $monitoring->isVisibleTo($user), 404);
        $notificationIds = collect([$monitoringNotification->id]);

        $notificationIds = $notificationIds->map(static fn (mixed $id): string => (string) $id)->values()->all();

        MonitoringNotificationState::query()->where('user_id', $user->id)->whereIn('monitoring_notification_id', $notificationIds)
            ->update(['read_at' => Date::now()]);

        return $notificationIds;
    }

    public function markAllRead(User $user): void
    {
        MonitoringNotificationState::query()->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => Date::now()]);
    }

    /**
     * @return Collection<int, array{
     *     notification_id: string,
     *     monitoring_id: string,
     *     monitor_name: string,
     *     target: string,
     *     type: string,
     *     latest_status_code: int|null,
     *     latest_checked_at: string|null,
     *     latest_status_change_at: string|null,
     *     status_identifier: string,
     *     status_key: string,
     *     status_change_key: string,
     *     badge_type: string,
     *     read: bool
     * }>
     */
    public function getStatusBoardEntries(bool $showRead, int $offset = 0, int $limit = 5): Collection
    {
        $statusChangeNotifications = MonitoringNotification::query()
            ->withoutGlobalScopes()
            ->select([
                'monitoring_notifications.id',
                'monitoring_notifications.monitoring_id',
                'monitoring_notifications.message',
                'monitoring_notification_states.read_at',
                'monitoring_notifications.created_at',
            ])
            ->join('monitoring_notification_states', 'monitoring_notification_states.monitoring_notification_id', '=', 'monitoring_notifications.id')
            ->join('monitorings', 'monitoring_notifications.monitoring_id', '=', 'monitorings.id')
            ->where('monitoring_notification_states.user_id', auth()->id())
            ->where(function ($query): void {
                $query->where('monitorings.user_id', auth()->id())
                    ->orWhereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('team_memberships')
                            ->whereColumn('team_memberships.team_id', 'monitorings.team_id')
                            ->where('team_memberships.user_id', auth()->id());
                    });
            })
            ->whereNull('monitorings.deleted_at')
            ->where('monitoring_notifications.type', NotificationType::STATUS_CHANGE->value)
            ->unless($showRead, fn ($query) => $query->whereNull('monitoring_notification_states.read_at'))
            ->whereNotExists(function ($query) use ($showRead): void {
                $query->selectRaw('1')
                    ->from('monitoring_notifications as newer_notifications')
                    ->join('monitoring_notification_states as newer_states', 'newer_states.monitoring_notification_id', '=', 'newer_notifications.id')
                    ->whereColumn('newer_notifications.monitoring_id', 'monitoring_notifications.monitoring_id')
                    ->whereColumn('newer_states.user_id', 'monitoring_notification_states.user_id')
                    ->where('newer_notifications.type', NotificationType::STATUS_CHANGE->value)
                    ->when(! $showRead, fn ($query) => $query->whereNull('newer_states.read_at'))
                    ->where(function ($query): void {
                        $query->whereColumn('newer_notifications.created_at', '>', 'monitoring_notifications.created_at')
                            ->orWhere(function ($query): void {
                                $query->whereColumn('newer_notifications.created_at', 'monitoring_notifications.created_at')
                                    ->whereColumn('newer_notifications.id', '>', 'monitoring_notifications.id');
                            });
                    });
            })
            ->latest('monitoring_notifications.created_at')
            ->orderByDesc('monitoring_notifications.id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        $monitorings = Monitoring::query()
            ->select([
                'id',
                'name',
                'target',
                'type',
                'maintenance_from',
                'maintenance_until',
            ])
            ->withMaintenanceWindowState()
            ->whereKey($statusChangeNotifications->pluck('monitoring_id')->all())
            ->with([
                'latestResponseResult' => fn ($builder) => $builder->select([
                    'monitoring_response_results.id',
                    'monitoring_response_results.monitoring_id',
                    'monitoring_response_results.http_status_code',
                    'monitoring_response_results.created_at',
                ]),
            ])
            ->get()
            ->keyBy('id');

        return $statusChangeNotifications->map(function (MonitoringNotification $monitoringNotification) use ($monitorings): array {
            /** @var Monitoring $monitoring */
            $monitoring = $monitorings->get($monitoringNotification->monitoring_id);
            /** @var MonitoringResponse|null $latestResponse */
            $latestResponse = $monitoring->getRelation('latestResponseResult');
            $latestStatusCode = $latestResponse?->http_status_code;
            $maintenanceActive = $monitoring->isUnderMaintenance();

            $statusIdentifier = MonitoringStatusMeta::identifier($latestStatusCode !== null ? (int) $latestStatusCode : null, $maintenanceActive);
            $statusChangeMessage = $monitoringNotification->message;
            $latestCheckedAt = $latestResponse?->created_at;
            $latestStatusChangeAt = $monitoringNotification->created_at;
            $statusChangeIdentifier = $maintenanceActive
                ? 'maintenance'
                : MonitoringNotification::extractStatusChangeIdentifierFromMessage($statusChangeMessage);

            return [
                'notification_id' => $monitoringNotification->id,
                'monitoring_id' => $monitoring->id,
                'monitor_name' => $monitoring->name,
                'target' => $monitoring->target,
                'type' => $monitoring->type->value,
                'latest_status_code' => $latestStatusCode !== null ? (int) $latestStatusCode : null,
                'latest_checked_at' => $latestCheckedAt ? Date::parse($latestCheckedAt)->toIso8601String() : null,
                'latest_status_change_at' => $latestStatusChangeAt ? Date::parse($latestStatusChangeAt)->toIso8601String() : null,
                'status_identifier' => MonitoringStatusMeta::statusIdentifier(
                    $latestStatusCode !== null ? (int) $latestStatusCode : null,
                    $maintenanceActive
                ),
                'status_key' => MonitoringStatusMeta::statusKey(
                    $latestStatusCode !== null ? (int) $latestStatusCode : null,
                    $maintenanceActive
                ),
                'status_change_key' => 'notifications.status_change.' . $statusChangeIdentifier,
                'badge_type' => MonitoringStatusMeta::badgeType($statusIdentifier),
                'read' => $monitoringNotification->read_at !== null,
            ];
        });
    }

    public function getUnreadNotificationCount(): int
    {
        $total = MonitoringNotification::query()
            ->withoutGlobalScopes()
            ->join('monitoring_notification_states', 'monitoring_notification_states.monitoring_notification_id', '=', 'monitoring_notifications.id')
            ->join('monitorings', 'monitoring_notifications.monitoring_id', '=', 'monitorings.id')
            ->where('monitoring_notification_states.user_id', auth()->id())
            ->where(function ($query): void {
                $query->where('monitorings.user_id', auth()->id())
                    ->orWhereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('team_memberships')
                            ->whereColumn('team_memberships.team_id', 'monitorings.team_id')
                            ->where('team_memberships.user_id', auth()->id());
                    });
            })
            ->whereNull('monitorings.deleted_at')
            ->whereNull('monitoring_notification_states.read_at')
            ->selectRaw(
                <<<'SQL'
                count(distinct case
                    when monitoring_notifications.type = ?
                    then monitoring_notifications.monitoring_id
                end) + coalesce(sum(case
                    when monitoring_notifications.type != ?
                    then 1
                    else 0
                end), 0) as total
                SQL,
                [
                    NotificationType::STATUS_CHANGE->value,
                    NotificationType::STATUS_CHANGE->value,
                ]
            )
            ->value('total');

        return (int) $total;
    }

    /**
     * @return Collection<string, int>
     */
    public function getUnreadNotificationCountsByUser(): Collection
    {
        return MonitoringNotification::query()
            ->withoutGlobalScopes()
            ->join('monitoring_notification_states', 'monitoring_notification_states.monitoring_notification_id', '=', 'monitoring_notifications.id')
            ->join('monitorings', 'monitoring_notifications.monitoring_id', '=', 'monitorings.id')
            ->whereNull('monitoring_notification_states.read_at')
            ->where(function ($query): void {
                $query->whereColumn('monitorings.user_id', 'monitoring_notification_states.user_id')
                    ->orWhereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('team_memberships')
                            ->whereColumn('team_memberships.team_id', 'monitorings.team_id')
                            ->whereColumn('team_memberships.user_id', 'monitoring_notification_states.user_id');
                    });
            })
            ->whereNull('monitorings.deleted_at')
            ->selectRaw(
                <<<'SQL'
                monitoring_notification_states.user_id as user_id,
                count(distinct case
                    when monitoring_notifications.type = ?
                    then monitoring_notifications.monitoring_id
                end) + coalesce(sum(case
                    when monitoring_notifications.type != ?
                    then 1
                    else 0
                end), 0) as total
                SQL,
                [
                    NotificationType::STATUS_CHANGE->value,
                    NotificationType::STATUS_CHANGE->value,
                ]
            )
            ->groupBy('monitoring_notification_states.user_id')
            ->pluck('total', 'user_id')
            ->map(fn (int|string $total): int => (int) $total);
    }

    private function filterMobileEntriesByEventType(Builder $builder, string $eventType, User $user): Builder
    {
        return match ($eventType) {
            'incident' => $builder->statusChange()->whereRaw('lower(monitoring_notifications.message) like ?', ['%down%']),
            'recovery' => $builder->statusChange()->whereRaw('lower(monitoring_notifications.message) like ?', ['%up%']),
            'maintenance' => $builder->statusChange()->whereRaw('lower(monitoring_notifications.message) like ?', ['%maintenance%']),
            'performance_degraded' => $builder->performance()->whereRaw('lower(monitoring_notifications.message) like ?', ['%degraded%']),
            'performance_recovered' => $builder->performance()->whereRaw('lower(monitoring_notifications.message) not like ?', ['%degraded%']),
            'ssl_expiring' => $builder->sslExpiry()->whereRaw('upper(monitoring_notifications.message) not like ?', ['%EXPIRED%']),
            'ssl_expired' => $builder->sslExpiry()->whereRaw('upper(monitoring_notifications.message) like ?', ['%EXPIRED%']),
            'domain_expiring' => $builder->domainExpiry()->whereRaw('upper(monitoring_notifications.message) not like ?', ['%EXPIRED%']),
            'domain_expired' => $builder->domainExpiry()->whereRaw('upper(monitoring_notifications.message) like ?', ['%EXPIRED%']),
            'delivery_failure' => $builder->whereExists(function (QueryBuilder $queryBuilder) use ($user): void {
                $queryBuilder->selectRaw('1')->from('notification_channel_deliveries')
                    ->whereColumn('notification_channel_deliveries.monitoring_notification_id', 'monitoring_notifications.id')
                    ->where('notification_channel_deliveries.user_id', $user->id)
                    ->where('notification_channel_deliveries.status', NotificationDeliveryStatus::FAILED);
            }),
        };
    }

    private function mobileEventType(MonitoringNotification $monitoringNotification): string
    {
        $message = mb_strtolower($monitoringNotification->message);

        return match ($monitoringNotification->type) {
            NotificationType::STATUS_CHANGE => match (true) {
                str_contains($message, 'maintenance') => 'maintenance',
                str_contains($message, 'down') => 'incident',
                str_contains($message, 'up') => 'recovery',
                default => 'status_change',
            },
            NotificationType::PERFORMANCE => str_contains($message, 'degraded') ? 'performance_degraded' : 'performance_recovered',
            NotificationType::SSL_EXPIRY => str_contains(mb_strtoupper($monitoringNotification->message), 'EXPIRED') ? 'ssl_expired' : 'ssl_expiring',
            NotificationType::DOMAIN_EXPIRY => str_contains(mb_strtoupper($monitoringNotification->message), 'EXPIRED') ? 'domain_expired' : 'domain_expiring',
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decodeCursor(string $cursor): array
    {
        $decoded = json_decode((string) base64_decode($cursor, true), true);
        abort_unless(is_array($decoded) && isset($decoded['created_at'], $decoded['id']), 422);

        try {
            $createdAt = Date::parse((string) $decoded['created_at'])->toDateTimeString();
        } catch (Throwable) {
            abort(422);
        }

        return [$createdAt, (string) $decoded['id']];
    }

    private function encodeCursor(MonitoringNotification $monitoringNotification): string
    {
        return base64_encode(json_encode(['created_at' => $monitoringNotification->created_at?->toIso8601String(), 'id' => $monitoringNotification->id], JSON_THROW_ON_ERROR));
    }
}
