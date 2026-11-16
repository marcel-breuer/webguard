<?php

declare(strict_types=1);

use App\Enums\SupportedLanguage;
use App\Http\Middleware\AuthenticateInstance;
use App\Http\Middleware\CheckUserRole;
use App\Http\Middleware\DenyScopedApiKeys;
use App\Http\Middleware\MeasureApiRequestMetrics;
use App\Http\Middleware\PreventCrawling;
use App\Http\Middleware\PreventRequestForgeryExceptBearer;
use App\Http\Middleware\RequireApiKeyAbility;
use App\Http\Middleware\RequireApiKeyManagement;
use App\Http\Middleware\RequireMcpReadAbility;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Http\Middleware\SetPublicCacheHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/status',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(PreventCrawling::class);
        $middleware->append(MeasureApiRequestMetrics::class);
        $middleware->encryptCookies([SupportedLanguage::cookieName()]);
        $middleware->web(SetLocaleMiddleware::class);
        $middleware->web(replace: [PreventRequestForgery::class => PreventRequestForgeryExceptBearer::class]);
        $middleware->preventRequestsDuringMaintenance([
            'api/instances/*',
            'api/monitorings/*',
            'gdpr',
        ]);
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);
        $middleware->alias([
            'role' => CheckUserRole::class,
            'auth.instance' => AuthenticateInstance::class,
            'public.cache' => SetPublicCacheHeaders::class,
            'api-key.ability' => RequireApiKeyAbility::class,
            'mcp.read' => RequireMcpReadAbility::class,
            'api-key.manage' => RequireApiKeyManagement::class,
            'api-key.deny' => DenyScopedApiKeys::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, Throwable $throwable): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
