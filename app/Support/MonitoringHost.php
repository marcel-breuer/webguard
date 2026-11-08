<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Monitoring;

final class MonitoringHost
{
    public static function for(Monitoring $monitoring): ?string
    {
        $target = mb_trim((string) $monitoring->target);

        if ($target === '') {
            return null;
        }

        $host = str_contains($target, '://') ? parse_url($target, PHP_URL_HOST) : $target;

        if (! is_string($host) || $host === '') {
            return null;
        }

        return self::normalize($host);
    }

    /**
     * Hosts match when they are equal apart from a leading "www." label.
     */
    public static function same(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return self::withoutWww($left) === self::withoutWww($right);
    }

    /**
     * Determine whether the host belongs to the given registrable domain.
     */
    public static function belongsToDomain(?string $host, ?string $domain): bool
    {
        if ($host === null || $domain === null) {
            return false;
        }

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private static function normalize(string $host): string
    {
        return mb_strtolower(mb_rtrim(mb_trim($host, '[]'), '.'));
    }

    private static function withoutWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;
    }
}
