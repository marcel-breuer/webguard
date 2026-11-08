<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Class Package
 *
 * Represents a subscription package with specific monitoring limits and pricing.
 *
 * @property string $id
 * @property int $monitoring_limit
 * @property float $price
 * @property bool $is_selectable
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, User> $users
 */
#[Fillable([
    'monitoring_limit',
    'price',
    'is_selectable',
])]
#[WithoutIncrementing]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;
    use HasUlids;

    protected $keyType = 'string';

    /**
     * Get the cheapest selectable package available.
     *
     * This method retrieves the package with the cheapest price, which is typically
     * the free package, and orders it by monitoring limit in descending order.
     */
    public static function cheapest(): ?self
    {
        return self::query()
            ->where('is_selectable', true)
            ->orderBy('price', 'asc')
            ->orderByDesc('monitoring_limit')
            ->first();
    }

    /**
     * Get the users for the package.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_selectable' => 'boolean',
        ];
    }
}
