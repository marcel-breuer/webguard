<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MobilePushDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $platform
 * @property string $push_provider
 * @property string $push_token
 * @property string $token_hash
 * @property string|null $device_name
 * @property string|null $app_version
 * @property string|null $locale
 * @property string|null $timezone
 * @property bool $enabled
 * @property Carbon|null $notifications_authorized_at
 * @property Carbon|null $last_registered_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'user_id',
    'platform',
    'push_provider',
    'push_token',
    'token_hash',
    'device_name',
    'app_version',
    'locale',
    'timezone',
    'enabled',
    'notifications_authorized_at',
    'last_registered_at',
    'last_seen_at',
    'revoked_at',
])]
#[WithoutIncrementing]
class MobilePushDevice extends Model
{
    /** @use HasFactory<MobilePushDeviceFactory> */
    use HasFactory;
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<MobilePushDevice>  $builder
     * @return Builder<MobilePushDevice>
     */
    #[Scope]
    protected function active(Builder $builder): Builder
    {
        return $builder
            ->where('enabled', true)
            ->whereNull('revoked_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'enabled' => 'boolean',
            'notifications_authorized_at' => 'datetime',
            'last_registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
