<?php

declare(strict_types=1);

namespace App\Enums;

enum ApiKeyAbility: string
{
    case SERVER_HEALTH_WRITE = 'server-health:write';
    case ANALYTICS_READ = 'analytics:read';
    case MANAGEMENT_READ = 'management:read';
    case MANAGEMENT_WRITE = 'management:write';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
