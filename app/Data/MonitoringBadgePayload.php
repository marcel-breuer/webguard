<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;

final readonly class MonitoringBadgePayload implements JsonSerializable
{
    public function __construct(
        public string $name,
        public string $status,
        public string $statusLabel,
        public ?int $statusCode,
        public string $statusIdentifier,
        public string $statusKey,
        public ?string $checkedAt,
        public ?string $checkedAtHuman,
        public MonitoringBadgeUptimePayload $uptime,
        public string $publicUrl,
        /** @var array{'30_days': int, '90_days': int, '365_days': int} */
        public array $incidents,
        /** @var array{valid: bool|null, expires_at: string|null} */
        public array $ssl,
        /** @var array{valid: bool|null, expires_at: string|null} */
        public array $domain,
        /** @var array{active: bool, starts_at: string|null, ends_at: string|null} */
        public array $maintenance
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'status_code' => $this->statusCode,
            'status_identifier' => $this->statusIdentifier,
            'status_key' => $this->statusKey,
            'checked_at' => $this->checkedAt,
            'checked_at_human' => $this->checkedAtHuman,
            'uptime' => $this->uptime->toArray(),
            'public_url' => $this->publicUrl,
            'incidents' => $this->incidents,
            'ssl' => $this->ssl,
            'domain' => $this->domain,
            'maintenance' => $this->maintenance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
