<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Routing\Route;

/**
 * Defines which authenticated `/api` routes a scoped management key may reach.
 *
 * The list is an allowlist: account, session, API key, admin, and mobile routes stay
 * reachable only for browser sessions and legacy personal access tokens, so a
 * scoped key can never be used to widen its own permissions or take over an account.
 */
final class ExternalApiSurface
{
    /**
     * Route name prefixes (without the shared `app.` prefix) a management key may use.
     *
     * @var list<string>
     */
    private const MANAGEMENT_PREFIXES = [
        'dashboard',
        'incidents.',
        'maintenance.',
        'monitoring-groups.',
        'monitorings.',
        'notifications.',
        'profile.notification-settings.',
        'status-pages.',
        'teams.',
    ];

    public static function allowsManagementKey(Route $route): bool
    {
        $name = $route->getName();

        if (! is_string($name) || ! str_starts_with($name, 'app.')) {
            return false;
        }

        $relative = mb_substr($name, 4);

        foreach (self::MANAGEMENT_PREFIXES as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function isAnalyticsRoute(Route $route): bool
    {
        return is_string($route->getName()) && str_starts_with($route->getName(), 'app.monitorings.analytics.');
    }
}
