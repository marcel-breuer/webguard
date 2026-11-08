<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\MonitoringStatus;
use JsonSerializable;

final readonly class MonitoringStatusPayload implements JsonSerializable
{
    public function __construct(
        public MonitoringStatus|string $status,
        public ?string $since,
        public ?string $checkedAt,
        public string $next,
        public int $interval,
        public ?int $statusCode,
        public ?string $statusChangedAt,
        public string $statusIdentifier,
        public string $statusKey,
        /** @var array<string, mixed>|null */
        public ?array $regionalConsensus = null,
        public ?MonitoringSummaryPayload $monitoring = null
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'status' => $this->status,
            'since' => $this->since,
            'checked_at' => $this->checkedAt,
            'next' => $this->next,
            'interval' => $this->interval,
            'status_code' => $this->statusCode,
            'status_changed_at' => $this->statusChangedAt,
            'status_identifier' => $this->statusIdentifier,
            'status_key' => $this->statusKey,
        ];

        if ($this->regionalConsensus !== null) {
            $payload['regional_consensus'] = $this->regionalConsensus;
        }

        if ($this->monitoring) {
            $payload['monitoring'] = $this->monitoring->toArray();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
