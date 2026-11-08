<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

#[Fillable([
    'status_page_id',
    'email',
    'confirmation_token_hash',
    'unsubscribe_token',
    'verified_at',
])]
#[Table(name: 'status_page_subscriptions', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class StatusPageSubscription extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;
    use HasUlids;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<StatusPage, $this>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
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
     * @param  Builder<StatusPageSubscription>  $builder
     * @return Builder<StatusPageSubscription>
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
