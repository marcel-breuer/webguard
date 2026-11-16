<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Runs one catalogue entry against its REST route.
 */
final readonly class McpRestToolHandler implements ToolHandlerInterface
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(
        private array $definition,
        private McpRestBridge $bridge,
        private Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        $routeParameters = [];

        /** @var array<string, string> $pathMap */
        $pathMap = $this->definition['path'] ?? [];

        foreach ($pathMap as $routeParameter => $inputKey) {
            $routeParameters[$routeParameter] = (string) ($arguments[$inputKey] ?? '');
            unset($arguments[$inputKey]);
        }

        $headers = [];

        if ($this->definition['idempotencyKey'] ?? false) {
            $key = $arguments['idempotency_key'] ?? null;
            $headers['Idempotency-Key'] = is_string($key) && $key !== '' ? $key : (string) Str::uuid();
            unset($arguments['idempotency_key']);
        }

        return $this->bridge->call(
            $this->request,
            (string) $this->definition['method'],
            (string) $this->definition['route'],
            $routeParameters,
            $arguments,
            $headers,
        );
    }
}
