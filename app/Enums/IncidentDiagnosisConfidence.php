<?php

declare(strict_types=1);

namespace App\Enums;

enum IncidentDiagnosisConfidence: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    private const int HIGH_THRESHOLD = 70;

    private const int MEDIUM_THRESHOLD = 40;

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= self::HIGH_THRESHOLD => self::HIGH,
            $score >= self::MEDIUM_THRESHOLD => self::MEDIUM,
            default => self::LOW,
        };
    }

    public function label(): string
    {
        return __('incidents.diagnosis.confidence.' . $this->value);
    }
}
