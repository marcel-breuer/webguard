<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'monitoring_id',
    'user_id',
    'notification_on_failure',
    'notification_channels',
    'ssl_expiry_warning_days',
])]
#[Table(name: 'monitoring_notification_preferences', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class MonitoringNotificationPreference extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;
    use HasUlids;

    /**
     * @return BelongsTo<Monitoring, $this>
     */
    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_on_failure' => 'boolean',
            'notification_channels' => 'array',
            'ssl_expiry_warning_days' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
