<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\McpServerFactory;
use Illuminate\Http\Request;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

final class McpController extends Controller
{
    public function __invoke(Request $request, McpServerFactory $mcpServerFactory): Response
    {
        /** @var User $user */
        $user = $request->user();
        $psr17Factory = new Psr17Factory();
        $serverRequest = (new PsrHttpFactory(
            $psr17Factory,
            $psr17Factory,
            $psr17Factory,
            $psr17Factory,
        ))->createRequest($request);
        $allowedHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        $streamableHttpTransport = new StreamableHttpTransport(
            $serverRequest,
            $psr17Factory,
            $psr17Factory,
            middleware: [
                new CorsMiddleware(),
                new DnsRebindingProtectionMiddleware([
                    is_string($allowedHost) && $allowedHost !== '' ? $allowedHost : 'localhost',
                ]),
            ],
        );

        $response = $mcpServerFactory->make($user)->run($streamableHttpTransport);

        return (new HttpFoundationFactory())->createResponse($response);
    }
}
