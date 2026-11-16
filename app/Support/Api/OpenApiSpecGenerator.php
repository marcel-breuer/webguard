<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Builds the OpenAPI 3.1 contract of the token-accessible REST API from the registered routes.
 *
 * Paths, methods, path parameters, required abilities, and request fields come from the
 * routes and their form requests, so the committed `docs/api/openapi.json` can only drift
 * from the implementation by failing the drift test.
 */
final class OpenApiSpecGenerator
{
    public const SPEC_PATH = 'docs/api/openapi.json';

    /**
     * Route name prefixes that are deliberately not part of the contract, with the reason.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        'mobile.' => 'Native app contract with its own token type; documented in docs/api/external-v1.md.',
        'instances.' => 'Scanner-instance API; documented in docs/integrations/webguard-instance-api.md.',
        'auth.' => 'Browser sign-in, registration, and password flows; not available to tokens.',
        'server-health.' => 'Agent telemetry ingestion; documented in docs/integrations/server-agent-api.md.',
        'translations.' => 'Frontend translation bundle.',
        'mcp' => 'Model Context Protocol endpoint; documented in docs/mcp.md.',
        'api.docs' => 'Redirect to the generated public reference.',
        'scribe' => 'Generated public reference assets.',
        'captcha' => 'Browser captcha image.',
    ];

    private const READ_ABILITY = 'management:read';

    private const WRITE_ABILITY = 'management:write';

    public function __construct(private readonly Router $router) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $paths = [];

        foreach ($this->documentedRoutes() as $route) {
            $uri = '/' . $route->uri();

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$uri][mb_strtolower($method)] = $this->operation($route, $method);
            }
        }

        ksort($paths);
        foreach ($paths as &$operations) {
            ksort($operations);
        }
        unset($operations);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'WebGuard REST API',
                'version' => '1.0.0',
                'description' => 'Token-accessible REST API of WebGuard Core. Generated from the application routes; see docs/api/rest-api.md for conventions.',
            ],
            'servers' => [['url' => '/']],
            'security' => [['bearerAuth' => []]],
            'tags' => $this->tags($paths),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'WebGuard API key or personal access token. Scoped keys need the ability named in x-required-ability.',
                    ],
                ],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => ['message' => ['type' => 'string']],
                    ],
                    'ValidationError' => [
                        'type' => 'object',
                        'required' => ['message', 'errors'],
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        ],
                    ],
                    'Object' => ['type' => 'object', 'additionalProperties' => true],
                ],
                'responses' => [
                    'Unauthorized' => ['description' => 'Missing, invalid, or revoked token.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    'Forbidden' => ['description' => 'The token lacks the required ability, or the caller may not access the resource.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    'NotFound' => ['description' => 'The resource does not exist or is not visible to the caller.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    'ValidationFailed' => ['description' => 'The request failed validation.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]]],
                    'TooManyRequests' => ['description' => 'Rate limit exceeded; see Retry-After.', 'headers' => ['Retry-After' => ['schema' => ['type' => 'integer']]], 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                ],
            ],
        ];
    }

    /**
     * @return list<Route>
     */
    public function documentedRoutes(): array
    {
        return array_values(array_filter(
            $this->apiRoutes(),
            fn (Route $route): bool => $this->exclusionReason($route) === null,
        ));
    }

    /**
     * @return list<Route>
     */
    public function apiRoutes(): array
    {
        return array_values(array_filter(
            $this->router->getRoutes()->getRoutes(),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'api/'),
        ));
    }

    public function exclusionReason(Route $route): ?string
    {
        $name = (string) $route->getName();
        $relative = str_starts_with($name, 'app.') ? mb_substr($name, 4) : $name;

        if ($name === '' || ! str_starts_with($name, 'app.') && ! str_starts_with($name, 'api-keys.') && ! str_starts_with($name, 'public.')) {
            return $this->reasonFor($name) ?? 'Unnamed or unsupported route.';
        }

        if (str_starts_with($name, 'app.session.') || str_starts_with($name, 'app.onboarding.') || str_starts_with($name, 'app.appearance.') || str_starts_with($name, 'app.locale.')) {
            return 'First-party UI session and preference route; not part of the token API.';
        }

        return $this->reasonFor($relative);
    }

    private function reasonFor(string $name): ?string
    {
        foreach (self::EXCLUDED as $prefix => $reason) {
            if ($name === $prefix || str_starts_with($name, $prefix)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $method): array
    {
        $name = (string) $route->getName();
        $isPublic = str_starts_with($name, 'public.');
        $isRead = in_array($method, ['GET', 'HEAD'], true);
        $segments = $this->tagSegments($name);
        $operation = [
            'operationId' => $name,
            'summary' => $this->summary($name),
            'tags' => [$segments],
            'x-required-ability' => $this->requiredAbility($route, $method, $isPublic),
        ];

        if ($isPublic) {
            $operation['security'] = [];
        }

        $parameters = $this->pathParameters($route);
        $schema = $this->requestSchema($route);

        if ($isRead) {
            foreach ($schema['properties'] as $field => $definition) {
                $parameters[] = [
                    'name' => $field,
                    'in' => 'query',
                    'required' => in_array($field, $schema['required'], true),
                    'schema' => $definition,
                ];
            }
        } elseif ($schema['properties'] !== [] || $schema['generic']) {
            $operation['requestBody'] = [
                'required' => $schema['required'] !== [],
                'content' => ['application/json' => ['schema' => $schema['generic']
                    ? ['$ref' => '#/components/schemas/Object']
                    : array_filter(['type' => 'object', 'properties' => $schema['properties'], 'required' => $schema['required']])]],
            ];
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $operation['responses'] = [
            '2XX' => ['description' => 'Successful response.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Object']]]],
            '401' => ['$ref' => '#/components/responses/Unauthorized'],
            '403' => ['$ref' => '#/components/responses/Forbidden'],
            '404' => ['$ref' => '#/components/responses/NotFound'],
            '422' => ['$ref' => '#/components/responses/ValidationFailed'],
            '429' => ['$ref' => '#/components/responses/TooManyRequests'],
        ];

        if ($isPublic) {
            unset($operation['responses']['401'], $operation['responses']['403']);
        }

        return $operation;
    }

    private function requiredAbility(Route $route, string $method, bool $isPublic): string
    {
        if ($isPublic) {
            return 'none';
        }

        if (! ExternalApiSurface::allowsManagementKey($route)) {
            return 'unscoped';
        }

        if (ExternalApiSurface::isAnalyticsRoute($route)) {
            return 'analytics:read|' . self::READ_ABILITY;
        }

        return in_array($method, ['GET', 'HEAD'], true) ? self::READ_ABILITY : self::WRITE_ABILITY;
    }

    private function tagSegments(string $name): string
    {
        if (str_starts_with($name, 'public.')) {
            return 'public';
        }

        $relative = preg_replace('/^app\./', '', $name) ?? $name;

        return explode('.', $relative)[0];
    }

    private function summary(string $name): string
    {
        $relative = preg_replace('/^(app|public)\./', '', $name) ?? $name;
        $parts = explode('.', $relative);
        $action = array_pop($parts);
        $subject = str_replace('-', ' ', implode(' ', $parts));

        return match ($action) {
            'index' => 'List ' . $subject,
            'store' => 'Create in ' . $subject,
            'show' => 'Get ' . $subject,
            'update' => 'Update ' . $subject,
            'destroy' => 'Delete from ' . $subject,
            default => ucfirst(str_replace('-', ' ', $relative === $action ? $action : $subject . ' ' . $action)),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pathParameters(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[] = [
                'name' => $parameter,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    /**
     * @return array{properties: array<string, array<string, mixed>>, required: list<string>, generic: bool}
     */
    private function requestSchema(Route $route): array
    {
        $formRequest = $this->formRequestClass($route);

        if ($formRequest === null) {
            return ['properties' => [], 'required' => [], 'generic' => ! in_array('GET', $route->methods(), true) && ! in_array('DELETE', $route->methods(), true)];
        }

        try {
            $request = new $formRequest();
            $rules = method_exists($request, 'rules') ? $request->rules() : [];
        } catch (Throwable) {
            return ['properties' => [], 'required' => [], 'generic' => true];
        }

        if (! is_array($rules)) {
            return ['properties' => [], 'required' => [], 'generic' => true];
        }

        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            if (! is_string($field) || str_contains($field, '.') || str_contains($field, '*')) {
                continue;
            }

            $tokens = array_map(static fn (mixed $rule): string => is_string($rule) ? $rule : '', is_array($fieldRules) ? $fieldRules : explode('|', (string) $fieldRules));
            $type = 'string';
            foreach (['integer' => 'integer', 'numeric' => 'number', 'boolean' => 'boolean', 'array' => 'array'] as $rule => $schemaType) {
                if (in_array($rule, $tokens, true)) {
                    $type = $schemaType;
                    break;
                }
            }

            $nullable = in_array('nullable', $tokens, true);
            $properties[$field] = ['type' => $nullable ? [$type, 'null'] : $type];

            if (in_array('required', $tokens, true)) {
                $required[] = $field;
            }
        }

        ksort($properties);
        sort($required);

        return ['properties' => $properties, 'required' => $required, 'generic' => $properties === []];
    }

    /**
     * @return class-string<FormRequest>|null
     */
    private function formRequestClass(Route $route): ?string
    {
        $action = $route->getActionName();
        [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $paths
     * @return list<array<string, string>>
     */
    private function tags(array $paths): array
    {
        $names = [];

        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                foreach ($operation['tags'] as $tag) {
                    $names[$tag] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names);

        return array_map(static fn (string $name): array => ['name' => $name], $names);
    }
}
