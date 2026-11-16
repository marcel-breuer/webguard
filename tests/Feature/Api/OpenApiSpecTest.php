<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Services\Mcp\McpToolCatalogue;
use App\Support\Api\OpenApiSpecGenerator;
use Tests\TestCase;

class OpenApiSpecTest extends TestCase
{
    public function test_committed_spec_is_up_to_date_with_the_routes(): void
    {
        $this->artisan('openapi:generate', ['--check' => true])
            ->expectsOutputToContain('up to date')
            ->assertSuccessful();
    }

    public function test_spec_operations_match_the_documented_routes_exactly(): void
    {
        $spec = $this->spec();
        $documented = [];

        foreach (resolve(OpenApiSpecGenerator::class)->documentedRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    $documented[] = $method . ' /' . $route->uri();
                }
            }
        }

        $inSpec = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $inSpec[] = mb_strtoupper($method) . ' ' . $path;
            }
        }

        sort($documented);
        sort($inSpec);

        $this->assertSame($documented, $inSpec, 'Routes and docs/api/openapi.json drifted. Run `php artisan openapi:generate`.');
        $this->assertContains('POST /api/monitorings', $inSpec);
        $this->assertContains('POST /api/status-pages', $inSpec);
        $this->assertContains('PATCH /api/profile/notification-settings', $inSpec);
    }

    public function test_every_api_route_is_either_documented_or_explicitly_excluded(): void
    {
        $generator = resolve(OpenApiSpecGenerator::class);

        foreach ($generator->apiRoutes() as $route) {
            $documented = $generator->exclusionReason($route) === null;
            $this->assertTrue(
                $documented || $generator->exclusionReason($route) !== '',
                'Route ' . $route->uri() . ' needs a documented decision.'
            );
        }

        $unnamed = array_filter($generator->apiRoutes(), static fn ($route): bool => (string) $route->getName() === '' && ! str_starts_with($route->uri(), 'api/_'));
        $this->assertSame([], array_map(static fn ($route): string => $route->uri(), array_values($unnamed)));
    }

    public function test_spec_is_structurally_valid(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertNotEmpty($spec['info']['title']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);

        $operationIds = [];
        $tags = array_column($spec['tags'], 'name');

        foreach ($spec['paths'] as $path => $operations) {
            preg_match_all('/\{([^}]+)\}/', (string) $path, $matches);

            foreach ($operations as $method => $operation) {
                $label = mb_strtoupper($method) . ' ' . $path;
                $this->assertNotEmpty($operation['operationId'], $label);
                $this->assertNotContains($operation['operationId'], $operationIds, 'Duplicate operationId ' . $operation['operationId']);
                $operationIds[] = $operation['operationId'];
                $this->assertNotEmpty($operation['summary'], $label);
                $this->assertContains($operation['tags'][0], $tags, $label);
                $this->assertArrayHasKey('x-required-ability', $operation, $label);
                $this->assertNotEmpty($operation['responses'], $label);

                $pathParameters = array_column(array_filter($operation['parameters'] ?? [], static fn (array $parameter): bool => $parameter['in'] === 'path'), 'name');
                sort($pathParameters);
                $expected = $matches[1];
                sort($expected);
                $this->assertSame($expected, $pathParameters, $label . ' path parameters');

                if ($operation['x-required-ability'] !== 'none') {
                    $this->assertArrayHasKey('401', $operation['responses'], $label);
                    $this->assertArrayNotHasKey('security', $operation, $label);
                }
            }
        }

        $this->assertRefsResolve($spec, $spec);
    }

    public function test_mcp_tools_map_to_documented_operations_with_matching_abilities(): void
    {
        $spec = $this->spec();
        $byId = [];

        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $method => $operation) {
                $byId[$operation['operationId']] = [mb_strtoupper($method), $operation['x-required-ability']];
            }
        }

        foreach (McpToolCatalogue::definitions() as $definition) {
            $this->assertArrayHasKey($definition['route'], $byId, $definition['name'] . ' maps to an undocumented route');
            [$method, $ability] = $byId[$definition['route']];
            $this->assertSame($method, $definition['method'], $definition['name']);
            $this->assertSame($definition['access'] === 'write' ? 'management:write' : 'management:read', $ability, $definition['name']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $contents = file_get_contents(base_path(OpenApiSpecGenerator::SPEC_PATH));
        $this->assertIsString($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $root
     */
    private function assertRefsResolve(array $node, array $root): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $target = $root;
                foreach (explode('/', mb_ltrim($value, '#/')) as $segment) {
                    $this->assertArrayHasKey($segment, $target, 'Unresolved $ref ' . $value);
                    $target = $target[$segment];
                }
            } elseif (is_array($value)) {
                $this->assertRefsResolve($value, $root);
            }
        }
    }
}
