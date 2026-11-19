<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use Illuminate\Http\Request;
use Mcp\Server\Exception\ResourceReadException;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Mcp\Server\Handler\ResourceTemplateHandlerInterface;

/**
 * Serves read-only resources from the same REST routes as the matching tools.
 */
final readonly class McpResourceHandler implements ResourceHandlerInterface, ResourceTemplateHandlerInterface
{
    /**
     * @param  array<string, string>  $path  Route parameter => URI template variable.
     * @param  array<string, scalar>  $query
     */
    public function __construct(
        private string $routeName,
        private array $path,
        private array $query,
        private McpRestBridge $bridge,
        private Request $request,
    ) {}

    /**
     * @param  array<string, string>|ClientGateway  $variablesOrGateway
     */
    public function read(string $uri, array|ClientGateway $variablesOrGateway = [], ?ClientGateway $gateway = null): mixed
    {
        $variables = is_array($variablesOrGateway) ? $variablesOrGateway : [];

        $routeParameters = [];
        foreach ($this->path as $routeParameter => $variable) {
            $routeParameters[$routeParameter] = (string) ($variables[$variable] ?? '');
        }

        try {
            $payload = $this->bridge->call($this->request, 'GET', $this->routeName, $routeParameters, $this->query);
        } catch (ToolCallException $toolCallException) {
            throw new ResourceReadException($toolCallException->getMessage());
        }

        return new TextResourceContents(
            $uri,
            'application/json',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }
}
