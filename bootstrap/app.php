<?php

declare(strict_types=1);

use App\Enums\SupportedLanguage;
use App\Http\Middleware\AuthenticateInstance;
use App\Http\Middleware\CheckUserRole;
use App\Http\Middleware\PreventCrawling;
use App\Http\Middleware\RequireApiKeyAbility;
use App\Http\Middleware\RequireApiKeyManagement;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Http\Middleware\SetPublicCacheHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/status',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(PreventCrawling::class);
        $middleware->encryptCookies([SupportedLanguage::cookieName()]);
        $middleware->web(SetLocaleMiddleware::class);
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
            'api-key.manage' => RequireApiKeyManagement::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
