<?php

declare(strict_types=1);

use App\Http\Controllers\Api\External\ApiKeyController;
use App\Http\Controllers\Api\External\BearerServerHealthReportController;
use App\Http\Controllers\Api\External\PublicMonitoringLocationController;
use App\Http\Controllers\Api\FrontendTranslationController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\Mobile\MobileAuthController;
use App\Http\Controllers\Api\PublicStatusPayloadController;
use App\Http\Controllers\Api\PublicStatusSubscriptionController;
use App\Http\Controllers\Api\ServerHealthReportController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\PublicStatusPageUptimeCalendarController;
use Illuminate\Support\Facades\Route;

Route::get('/public/monitorings/{monitoring}/badge', [ApiController::class, 'badge'])
    ->middleware('throttle:60,1')
    ->name('public.monitorings.badge');
Route::get('/public/monitorings/{monitoring}/uptime-calendar', [ApiController::class, 'uptimeCalendar'])
    ->name('public.monitorings.uptime-calendar');
Route::get('/public/status-pages/{statusPage}/uptime-calendar', PublicStatusPageUptimeCalendarController::class)
    ->name('public.status-pages.uptime-calendar');
Route::get('/public/status/{status}', PublicStatusPayloadController::class)
    ->middleware('throttle:60,1')
    ->name('public.status.show');
Route::post('/public/status/{status}/subscribers', PublicStatusSubscriptionController::class)
    ->middleware('throttle:6,1')
    ->name('public.status.subscribers.store');
Route::post('/public/status/{status}/subscribers/confirm/{token}', [PublicStatusSubscriptionController::class, 'confirm'])
    ->middleware('throttle:6,1')
    ->name('public.status.subscribers.confirm');
Route::delete('/public/status/{status}/subscribers/unsubscribe/{token}', [PublicStatusSubscriptionController::class, 'destroy'])
    ->middleware('throttle:6,1')
    ->name('public.status.subscribers.destroy');

Route::post('/server-health/{token}', ServerHealthReportController::class)
    ->middleware('throttle:60,1')
    ->name('server-health.store');

// Keep the pre-namespace-consolidation endpoint available for installed agents.
Route::post('/v1/server-health/{token}', ServerHealthReportController::class)
    ->middleware('throttle:60,1')
    ->name('server-health.legacy.store');

Route::get('/public/monitoring-locations', PublicMonitoringLocationController::class)
    ->middleware('throttle:60,1')
    ->name('public.monitoring-locations.index');

Route::get('/translations', FrontendTranslationController::class)
    ->middleware('public.cache')
    ->name('translations.index');

Route::post('/server-health/monitorings/{monitoring}', BearerServerHealthReportController::class)
    ->middleware(['auth:sanctum', 'api-key.ability:server-health:write', 'throttle:60,1'])
    ->name('server-health.bearer.store');

Route::post('/v1/server-health/monitorings/{monitoring}', BearerServerHealthReportController::class)
    ->middleware(['auth:sanctum', 'api-key.ability:server-health:write', 'throttle:60,1'])
    ->name('server-health.bearer.legacy.store');

Route::any('/mcp', McpController::class)
    ->middleware(['auth:sanctum', 'mcp.read', 'throttle:60,1'])
    ->name('mcp');

Route::group(['prefix' => 'api-keys', 'as' => 'api-keys.', 'middleware' => ['auth:sanctum', 'api-key.manage']], function (): void {
    Route::get('/', [ApiKeyController::class, 'index'])->name('index');
    Route::post('/', [ApiKeyController::class, 'store'])->name('store');
    Route::get('/{apiKey}', [ApiKeyController::class, 'show'])->whereNumber('apiKey')->name('show');
    Route::delete('/{apiKey}', [ApiKeyController::class, 'destroy'])->whereNumber('apiKey')->name('destroy');
});

Route::post('/mobile/login', [MobileAuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('mobile.login');

Route::group(['prefix' => 'mobile', 'as' => 'mobile.', 'middleware' => ['auth:sanctum']], function (): void {
    Route::get('/me', [MobileAuthController::class, 'me'])->name('me');
    Route::post('/logout', [MobileAuthController::class, 'logout'])->name('logout');

    require __DIR__ . '/api/external.php';
});

require __DIR__ . '/api/instance.php';
