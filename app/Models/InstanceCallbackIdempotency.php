<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'instance_code',
    'endpoint',
    'idempotency_key',
    'request_hash',
    'response_status',
    'response_body',
    'expires_at',
])]
#[Table(name: 'instance_callback_idempotencies', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class InstanceCallbackIdempotency extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
