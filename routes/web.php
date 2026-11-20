<?php

declare(strict_types=1);

use App\Http\Controllers\HeartbeatPingController;
use App\Http\Controllers\LegacyPublicStatusPageController;
use App\Http\Controllers\PublicCrawlerPolicyController;
use App\Http\Controllers\PublicStatusSubscriptionController;
use App\Http\Controllers\TeamInvitationAcceptController;
use App\Http\Middleware\RequireExternalApiAbility;
use App\Http\Middleware\TrackApiUsage;
use App\Models\Monitoring;
use App\Support\PublicStatusResourceResolver;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');
Route::get('/robots.txt', [PublicCrawlerPolicyController::class, 'robots'])->name('public.robots');
Route::get('/sitemap.xml', [PublicCrawlerPolicyController::class, 'sitemap'])->name('public.sitemap');
Route::match(['get', 'post'], '/heartbeat/{token}', HeartbeatPingController::class)->name('monitorings.heartbeat.ping');
Route::permanentRedirect('/api/docs', '/api/reference')->name('api.docs.redirect');
Route::get('/team-invitations/{token}/accept', TeamInvitationAcceptController::class)
    ->name('team-invitations.accept');

$publicStatusTarget = static function (string $identifier): string {
    $resource = resolve(PublicStatusResourceResolver::class)->resolve($identifier);

    return mb_rtrim((string) config('app.url'), '/') . '/status/' . rawurlencode((string) $resource->getRouteKey());
};

Route::get('/label/{monitoring}', static fn (Monitoring $monitoring) => to_route('public-status-pages.show', $monitoring, 301))
    ->name('legacy-public-label')
    ->scopeBindings();
Route::post('/label/{monitoring}/subscribers', static fn (Monitoring $monitoring) => to_route('public-status-pages.subscribers.store', $monitoring, 307))
    ->middleware('throttle:6,1')
    ->name('legacy-public-label.subscribers.store')
    ->scopeBindings();
Route::get('/label/{monitoring}/subscribers/confirm/{token}', static fn (Monitoring $monitoring, string $token) => to_route('public-status-pages.subscribers.confirm', [$monitoring, 'token' => $token]))
    ->name('legacy-public-label.subscribers.confirm')
    ->scopeBindings();
Route::get('/label/{monitoring}/subscribers/unsubscribe/{token}', static fn (Monitoring $monitoring, string $token) => to_route('public-status-pages.subscribers.unsubscribe', [$monitoring, 'token' => $token]))
    ->name('legacy-public-label.subscribers.unsubscribe')
    ->scopeBindings();
Route::delete('/label/{monitoring}/subscribers/unsubscribe/{token}', static fn (Monitoring $monitoring, string $token) => to_route('public-status-pages.subscribers.destroy', [$monitoring, 'token' => $token], 307))
    ->name('legacy-public-label.subscribers.destroy')
    ->scopeBindings();
Route::match(['get', 'post', 'put', 'patch', 'delete'], '/label/{monitoring}/{path?}', static fn (Monitoring $monitoring) => to_route('public-status-pages.show', $monitoring, 307))
    ->where('path', '.*')
    ->name('legacy-public-label.forward')
    ->scopeBindings();

$statusPageUlidPattern = '(?i:[0-9A-HJKMNP-TV-Z]{26})';
$legacyStatusPageSlugPattern = '(?!(?i:[0-9A-HJKMNP-TV-Z]{26}))[A-Za-z0-9_-]+';

Route::post('/status/{statusPage}/subscribers', [PublicStatusSubscriptionController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('public-status-pages.subscribers.store')
    ->where('statusPage', $statusPageUlidPattern)
    ->scopeBindings();
Route::get('/status/{statusPage}/subscribers/confirm/{token}', [PublicStatusSubscriptionController::class, 'confirm'])
    ->name('public-status-pages.subscribers.confirm')
    ->where('statusPage', $statusPageUlidPattern)
    ->scopeBindings();
Route::get('/status/{statusPage}/subscribers/unsubscribe/{token}', [PublicStatusSubscriptionController::class, 'unsubscribe'])
    ->name('public-status-pages.subscribers.unsubscribe')
    ->where('statusPage', $statusPageUlidPattern)
    ->scopeBindings();
Route::delete('/status/{statusPage}/subscribers/unsubscribe/{token}', [PublicStatusSubscriptionController::class, 'destroy'])
    ->name('public-status-pages.subscribers.destroy')
    ->where('statusPage', $statusPageUlidPattern)
    ->scopeBindings();
Route::get('/status/{statusPage}', static fn (string $statusPage) => redirect()->away($publicStatusTarget($statusPage)))
    ->name('public-status-pages.show')
    ->where('statusPage', $statusPageUlidPattern);

Route::post('/status/{statusPageSlug}/subscribers', [LegacyPublicStatusPageController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('legacy-public-status-pages.subscribers.store')
    ->where('statusPageSlug', $legacyStatusPageSlugPattern)
    ->scopeBindings();
Route::get('/status/{statusPageSlug}/subscribers/confirm/{token}', [LegacyPublicStatusPageController::class, 'confirm'])
    ->name('legacy-public-status-pages.subscribers.confirm')
    ->where('statusPageSlug', $legacyStatusPageSlugPattern)
    ->scopeBindings();
Route::get('/status/{statusPageSlug}/subscribers/unsubscribe/{token}', [LegacyPublicStatusPageController::class, 'unsubscribe'])
    ->name('legacy-public-status-pages.subscribers.unsubscribe')
    ->where('statusPageSlug', $legacyStatusPageSlugPattern)
    ->scopeBindings();
Route::delete('/status/{statusPageSlug}/subscribers/unsubscribe/{token}', [LegacyPublicStatusPageController::class, 'destroy'])
    ->name('legacy-public-status-pages.subscribers.destroy')
    ->where('statusPageSlug', $legacyStatusPageSlugPattern)
    ->scopeBindings();
Route::get('/status/{statusPageSlug}', [LegacyPublicStatusPageController::class, 'show'])
    ->name('legacy-public-status-pages.show')
    ->where('statusPageSlug', $legacyStatusPageSlugPattern);

Route::get('/badge.js', function () {
    return response(file_get_contents(public_path('js/badge.js')))
        ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=60')
        ->header('Content-Type', 'application/javascript; charset=UTF-8')
        ->header('Cross-Origin-Resource-Policy', 'cross-origin')
        ->header('X-Content-Type-Options', 'nosniff');
})->name('badge.js');

Route::group(
    ['prefix' => 'api/auth', 'as' => 'auth.'],
    function (): void {
        require __DIR__ . '/api/auth-ui.php';
    }
);

Route::group(
    ['prefix' => 'api', 'as' => 'app.', 'middleware' => ['auth:sanctum', TrackApiUsage::class, RequireExternalApiAbility::class]],
    function (): void {
        require __DIR__ . '/api/ui.php';
        require __DIR__ . '/api/internal.php';
    }
);

require __DIR__ . '/auth.php';
