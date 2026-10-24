<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportedFormatLocale: string
{
    case DE_DE = 'de-DE';
    case EN_GB = 'en-GB';
    case EN_US = 'en-US';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
