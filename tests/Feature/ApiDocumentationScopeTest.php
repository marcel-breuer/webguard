<?php

declare(strict_types=1);

namespace Tests\Feature;

use Knuckles\Scribe\Matching\RouteMatcherInterface;
use Tests\TestCase;

class ApiDocumentationScopeTest extends TestCase
{
    public function test_scribe_matches_user_facing_api_routes_without_internal_routes(): void
    {
        $matchedRoutes = app(RouteMatcherInterface::class)->getRoutes(config('scribe.routes'));
        $routeNames = array_map(
            static fn (mixed $matchedRoute): ?string => $matchedRoute->getRoute()->getName(),
            $matchedRoutes,
        );

        $this->assertContains('public.status.show', $routeNames);
        $this->assertContains('api-keys.index', $routeNames);
        $this->assertNotContains('app.dashboard', $routeNames);
        $this->assertNotContains('app.monitorings.index', $routeNames);
        $this->assertNotContains('instances.monitorings.list', $routeNames);
        $this->assertNotContains('server-health.legacy.store', $routeNames);
        $this->assertNotContains('server-health.bearer.legacy.store', $routeNames);
        $this->assertNotContains('api.docs.redirect', $routeNames);
    }
}
