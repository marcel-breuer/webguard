<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

#[Fillable([
    'monitoring_id',
    'email',
    'confirmation_token_hash',
    'unsubscribe_token',
    'verified_at',
])]
#[Table(name: 'status_page_subscribers', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class StatusPageSubscriber extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use HasUlids;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<Monitoring, $this>
     */
    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }

    public function markVerified(): void
    {
        $this->forceFill([
            'confirmation_token_hash' => null,
            'verified_at' => $this->verified_at ?? Date::now(),
        ])->save();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * @param  Builder<StatusPageSubscriber>  $builder
     * @return Builder<StatusPageSubscriber>
     */
    #[Scope]
    protected function verified(Builder $builder): Builder
    {
        return $builder->whereNotNull('verified_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
