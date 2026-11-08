<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;

/**
 * @property string $api_key_hash
 */
#[Fillable([
    'code',
    'display_name',
    'country_code',
    'region',
    'ip_address',
    'api_key_hash',
    'is_active',
    'last_seen_at',
    'last_health_alert_status',
    'last_health_alerted_at',
])]
#[Hidden([
    'api_key_hash',
])]
#[WithoutIncrementing]
class ServerInstance extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;
    use HasUlids;

    protected $keyType = 'string';

    /**
     * @return HasMany<Monitoring, $this>
     */
    public function monitorings(): HasMany
    {
        return $this->hasMany(Monitoring::class, 'preferred_location', 'code');
    }

    public function verifyApiKey(string $apiKey): bool
    {
        return Hash::check($apiKey, $this->api_key_hash);
    }

    public function recordSeen(?CarbonInterface $timestamp = null): void
    {
        $timestamp ??= Date::now();
        $throttleSeconds = max(0, (int) config('monitoring.instance_seen_write_throttle_seconds', 60));

        if ($throttleSeconds > 0 && $this->last_seen_at?->greaterThan($timestamp->copy()->subSeconds($throttleSeconds))) {
            return;
        }

        $this->forceFill(['last_seen_at' => $timestamp])->saveQuietly();
    }

    public function healthStatus(?CarbonInterface $timestamp = null): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if (! $this->last_seen_at) {
            return 'never_seen';
        }

        $timestamp ??= Date::now();
        $staleAfterMinutes = max(1, (int) config('monitoring.instance_stale_after_minutes', 10));

        if ($this->last_seen_at->lessThan($timestamp->copy()->subMinutes($staleAfterMinutes))) {
            return 'stale';
        }

        return 'healthy';
    }

    /**
     * Scope a query to active server instances.
     *
     * @param  Builder<ServerInstance>  $builder
     * @return Builder<ServerInstance>
     */
    #[Scope]
    protected function active(Builder $builder): Builder
    {
        return $builder->where('is_active', true);
    }

    /**
     * Hash plain-text API keys before persisting.
     *
     * @return Attribute<mixed, string>
     */
    protected function apiKeyHash(): Attribute
    {
        return Attribute::make(set: function (string $value) {
            return ['api_key_hash' => Hash::make($value)];
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ip_address' => 'string',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_health_alerted_at' => 'datetime',
        ];
    }
}
