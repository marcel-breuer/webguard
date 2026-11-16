<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Http\Middleware\PreventRequestForgeryExceptBearer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use JsonException;
use Mcp\Exception\ToolCallException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Executes a REST route on behalf of an MCP tool call.
 *
 * The call is dispatched through the router with the caller's own bearer token, so
 * the route's middleware (token abilities, roles, rate limit), form requests, policies,
 * and controllers run exactly as they do for a direct REST request. The MCP layer adds
 * no business logic and cannot reach anything the REST API would refuse.
 */
final class McpRestBridge
{
    private const REDACTED = '[redacted]';

    private const SENSITIVE_KEY_PATTERN = '/(token|secret|password|authorization|api[_-]?key|credential|webhook_url|signing)/i';

    public function __construct(
        private readonly Application $application,
        private readonly Router $router,
    ) {}

    /**
     * @param  array<string, scalar|null>  $routeParameters
     * @param  array<string, mixed>  $payload  Query string for GET requests, JSON body otherwise.
     * @param  array<string, string>  $headers  Extra request headers, such as Idempotency-Key.
     * @return array<string, mixed>
     */
    public function call(Request $original, string $method, string $routeName, array $routeParameters = [], array $payload = [], array $headers = []): array
    {
        $uri = route($routeName, $routeParameters, false);
        $isRead = $method === 'GET';

        $subRequest = Request::create(
            $uri,
            $method,
            $isRead ? $payload : [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => (string) $original->headers->get('Authorization', ''),
                'HTTP_ACCEPT_LANGUAGE' => (string) $original->headers->get('Accept-Language', ''),
                'REMOTE_ADDR' => (string) $original->server->get('REMOTE_ADDR', '127.0.0.1'),
            ],
            $isRead ? null : json_encode($payload === [] ? new stdClass() : $payload, JSON_THROW_ON_ERROR),
        );
        $subRequest->attributes->set(PreventRequestForgeryExceptBearer::INTERNAL_DISPATCH_ATTRIBUTE, true);
        $subRequest->headers->set('X-Request-Id', (string) Str::uuid());

        foreach ($headers as $name => $value) {
            $subRequest->headers->set($name, $value);
        }

        $this->application->instance('request', $subRequest);

        try {
            $response = $this->router->dispatch($subRequest);
        } finally {
            $this->application->instance('request', $original);
        }

        return $this->interpret($response);
    }

    /**
     * Removes secrets before any value is handed to a model.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1 && ! is_bool($item)) {
                $value[$key] = $item === null || $item === '' ? $item : self::REDACTED;

                continue;
            }

            if (is_string($key) && $key === 'notification_channels' && is_array($item)) {
                $value[$key] = $this->redactChannels($item);

                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function interpret(Response $response): array
    {
        $status = $response->getStatusCode();
        $content = (string) $response->getContent();

        try {
            $decoded = $content === '' ? [] : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = [];
        }

        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 400) {
            throw new ToolCallException($this->errorMessage($status, $decoded));
        }

        if ($decoded === []) {
            return ['status' => 'ok'];
        }

        $redacted = $this->redact($decoded);

        return array_is_list($redacted) ? ['data' => $redacted] : $redacted;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function errorMessage(int $status, array $body): string
    {
        $label = match ($status) {
            401 => 'Authentication failed.',
            403 => 'This token is not allowed to perform this action.',
            404 => 'The resource was not found or is not accessible.',
            422 => 'The request was rejected by validation.',
            429 => 'Rate limit exceeded; retry later.',
            default => 'The request failed.',
        };

        $message = $label . ' (HTTP ' . $status . ')';

        if ($status === 422 && isset($body['errors']) && is_array($body['errors'])) {
            $message .= ' ' . json_encode($body['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($status === 422 && is_string($body['message'] ?? null)) {
            $message .= ' ' . $body['message'];
        }

        return $message;
    }

    /**
     * Channel configuration holds webhook URLs and bot credentials; only the on/off state is shared.
     *
     * @param  array<array-key, mixed>  $channels
     * @return array<array-key, mixed>
     */
    private function redactChannels(array $channels): array
    {
        $safe = [];

        foreach ($channels as $channel => $config) {
            $safe[$channel] = is_array($config)
                ? ['enabled' => (bool) ($config['enabled'] ?? false), 'configured' => array_filter($config, static fn (mixed $setting, string|int $name): bool => $name !== 'enabled' && $setting !== null && $setting !== '' && $setting !== false, ARRAY_FILTER_USE_BOTH) !== []]
                : $config;
        }

        return $safe;
    }
}
