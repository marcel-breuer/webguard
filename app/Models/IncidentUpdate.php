<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentUpdateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'incident_id',
    'status',
    'message',
    'notified_at',
    'mobile_idempotency_key',
])]
#[WithoutIncrementing]
class IncidentUpdate extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IncidentUpdateStatus::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
