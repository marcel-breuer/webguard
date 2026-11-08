<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatusPageComponentSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'status_page_id',
    'monitoring_group_id',
    'name',
    'description',
    'position',
    'source_type',
])]
#[WithoutIncrementing]
class StatusPageComponent extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use HasUlids;

    /**
     * @return BelongsTo<StatusPage, $this>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /**
     * @return BelongsTo<MonitoringGroup, $this>
     */
    public function monitoringGroup(): BelongsTo
    {
        return $this->belongsTo(MonitoringGroup::class);
    }

    /**
     * @return BelongsToMany<Monitoring, $this>
     */
    public function monitorings(): BelongsToMany
    {
        return $this->belongsToMany(Monitoring::class, 'status_page_component_monitoring')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'source_type' => StatusPageComponentSource::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
