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
        $routeUris = array_map(
            static fn (mixed $matchedRoute): string => $matchedRoute->getRoute()->uri(),
            $matchedRoutes,
        );

        $this->assertNotEmpty($routeUris);
        $this->assertSame(
            $routeUris,
            array_values(array_filter(
                $routeUris,
                static fn (string $uri): bool => str_starts_with($uri, 'api/public/'),
            )),
        );
        $this->assertContains('api/public/status/{status}', $routeUris);
        $this->assertNotContains('api/api-keys', $routeUris);
        $this->assertNotContains('api/translations', $routeUris);
        $this->assertNotContains('api/dashboard', $routeUris);
        $this->assertNotContains('api/instances/monitorings', $routeUris);
        $this->assertNotContains('api/server-health/{token}', $routeUris);
        $this->assertNotContains('api/v1/server-health/{token}', $routeUris);
    }
}
