<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'name',
    'slug',
    'description',
    'is_public',
])]
#[WithoutIncrementing]
class StatusPage extends Model
{
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
     * @return HasMany<StatusPageComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(StatusPageComponent::class)->orderBy('position');
    }

    /**
     * @return HasMany<StatusPageSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(StatusPageSubscription::class);
    }

    /**
     * @return HasMany<StatusPageAnnouncement, $this>
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(StatusPageAnnouncement::class);
    }

    /**
     * @return HasOne<StatusPageAnnouncement, $this>
     */
    public function activeAnnouncement(): HasOne
    {
        return $this->hasOne(StatusPageAnnouncement::class)
            ->whereNull('dismissed_at')
            ->latestOfMany();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
