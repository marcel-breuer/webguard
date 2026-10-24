<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'name',
    'description',
    'sla_target_percentage',
    'sla_report_enabled',
])]
#[Table(name: 'monitoring_groups', key: 'id', keyType: 'string')]
#[WithoutIncrementing]
class MonitoringGroup extends Model
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
     * @return BelongsToMany<Monitoring, $this>
     */
    public function monitorings(): BelongsToMany
    {
        return $this->belongsToMany(Monitoring::class, 'monitoring_group_monitoring')
            ->withTimestamps();
    }

    /**
     * @return HasMany<MaintenanceWindow, $this>
     */
    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sla_target_percentage' => 'decimal:2',
            'sla_report_enabled' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
