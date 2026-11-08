<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $monitoring_notification_id
 * @property string $channel
 * @property string $event_type
 * @property NotificationDeliveryStatus $status
 * @property array<string, mixed>|null $payload
 * @property string|null $error_message
 * @property Carbon|null $sent_at
 */
#[Fillable([
    'user_id',
    'monitoring_notification_id',
    'channel',
    'event_type',
    'status',
    'payload',
    'error_message',
    'sent_at',
])]
#[WithoutIncrementing]
class NotificationChannelDelivery extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use HasUlids;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MonitoringNotification, $this>
     */
    public function monitoringNotification(): BelongsTo
    {
        return $this->belongsTo(MonitoringNotification::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NotificationDeliveryStatus::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
