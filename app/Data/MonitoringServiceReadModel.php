<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Monitoring;
use JsonSerializable;

final readonly class MonitoringServiceReadModel implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $target,
        public ?string $type,
        public ?string $groupName,
        public string $status,
        public bool $hasOpenIncident,
        public ?string $lastCheckedAt,
        public ?float $responseTimeMs,
    ) {}

    public static function fromMonitoring(Monitoring $monitoring, string $status): self
    {
        $latestResponse = $monitoring->latestResponseResult;

        return new self(
            id: (string) $monitoring->getKey(),
            name: (string) $monitoring->name,
            target: (string) $monitoring->target,
            type: $monitoring->type->value,
            groupName: $monitoring->groups->first()?->name,
            status: $status,
            hasOpenIncident: $monitoring->latestIncident?->up_at === null && $monitoring->latestIncident !== null,
            lastCheckedAt: $latestResponse?->created_at?->toIso8601String(),
            responseTimeMs: $latestResponse?->response_time,
        );
    }

    /**
     * @return array{id:string,name:string,target:string,type:string|null,group_name:string|null,status:string,open_incident:bool,last_checked_at:string|null,response_time_ms:float|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'target' => $this->target,
            'type' => $this->type,
            'group_name' => $this->groupName,
            'status' => $this->status,
            'open_incident' => $this->hasOpenIncident,
            'last_checked_at' => $this->lastCheckedAt,
            'response_time_ms' => $this->responseTimeMs,
        ];
    }

    /**
     * @return array{id:string,name:string,target:string,type:string|null,group_name:string|null,status:string,open_incident:bool,last_checked_at:string|null,response_time_ms:float|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
