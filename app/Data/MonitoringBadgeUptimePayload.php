<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;

final readonly class MonitoringBadgeUptimePayload implements JsonSerializable
{
    public function __construct(
        public float|int|null $sevenDays,
        public float|int|null $thirtyDays,
        public float|int|null $ninetyDays,
        public float|int|null $year
    ) {}

    /**
     * @return array{'7_days': float|int|null, '30_days': float|int|null, '90_days': float|int|null, '365_days': float|int|null}
     */
    public function toArray(): array
    {
        return [
            '7_days' => $this->sevenDays,
            '30_days' => $this->thirtyDays,
            '90_days' => $this->ninetyDays,
            '365_days' => $this->year,
        ];
    }

    /**
     * @return array{'7_days': float|int|null, '30_days': float|int|null, '90_days': float|int|null, '365_days': float|int|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
