<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\RedirectWwwToNonWww;
use App\Http\View\Composers\NotificationComposer;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitoring;
use App\Models\MonitoringDailyResult;
use App\Models\MonitoringDomainResult;
use App\Models\MonitoringNotification;
use App\Models\MonitoringResponse;
use App\Models\MonitoringSslResult;
use App\Models\NotificationChannelDelivery;
use App\Models\PersonalAccessToken;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Models\TeamMembership;
use App\Models\User;
use App\Observers\IncidentObserver;
use App\Observers\MonitoringResponseObserver;
use App\Observers\MonitoringStatsCacheObserver;
use App\Observers\OperationsOverviewCacheObserver;
use App\Observers\UserObserver;
use App\Services\OperationalMetricsService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OperationalMetricsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Router $router): void
    {
        $this->registerOperationalMetricsListeners();

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        $router->pushMiddlewareToGroup('web', RedirectWwwToNonWww::class);

        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            $temporarySignedPath = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
                absolute: false
            );

            return url($temporarySignedPath);
        });

        Model::preventLazyLoading(! app()->isProduction());

        Incident::observe(IncidentObserver::class);
        Monitoring::observe(OperationsOverviewCacheObserver::class);
        MonitoringResponse::observe(MonitoringResponseObserver::class);
        MonitoringDailyResult::observe(MonitoringStatsCacheObserver::class);
        MonitoringDomainResult::observe(MonitoringStatsCacheObserver::class);
        MonitoringSslResult::observe(MonitoringStatsCacheObserver::class);
        TeamMembership::observe(OperationsOverviewCacheObserver::class);
        MaintenanceWindow::observe(OperationsOverviewCacheObserver::class);
        MonitoringNotification::observe(OperationsOverviewCacheObserver::class);
        NotificationChannelDelivery::observe(OperationsOverviewCacheObserver::class);
        StatusPage::observe(OperationsOverviewCacheObserver::class);
        StatusPageComponent::observe(OperationsOverviewCacheObserver::class);
        User::observe(UserObserver::class);
        View::composer('layouts.navigation', NotificationComposer::class);

        JsonResource::withoutWrapping();
    }

    private function registerOperationalMetricsListeners(): void
    {
        $metrics = $this->app->make(OperationalMetricsService::class);

        DB::listen(static function (QueryExecuted $query) use ($metrics): void {
            $metrics->recordSlowQuery($query->sql, $query->time, $query->connectionName);
        });

        Event::listen(CacheHit::class, static function () use ($metrics): void {
            $metrics->recordCacheHit();
        });

        Event::listen(CacheMissed::class, static function () use ($metrics): void {
            $metrics->recordCacheMiss();
        });

        Event::listen(JobProcessing::class, static function (JobProcessing $event) use ($metrics): void {
            $payload = $event->job->payload();
            $createdAt = $payload['created_at'] ?? null;

            if (! is_numeric($createdAt)) {
                $metrics->flushCacheMetrics();

                return;
            }

            $queue = method_exists($event->job, 'getQueue') ? (string) $event->job->getQueue() : 'unknown';
            $lagMs = max(0.0, (microtime(true) - (float) $createdAt) * 1000);

            $metrics->recordQueueJob($queue, $lagMs);
        });

        Event::listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], static function () use ($metrics): void {
            $metrics->flushCacheMetrics();
        });

        Event::listen(ScheduledTaskFinished::class, static function (ScheduledTaskFinished $event) use ($metrics): void {
            $summary = $event->task->getSummaryForDisplay();
            $taskName = preg_match('/\bartisan\s+([a-z0-9:_-]+)/i', $summary, $matches) === 1
                ? $matches[1]
                : 'scheduled-task';

            $metrics->recordScheduledTask((float) $event->runtime * 1000, $taskName);
        });

        Event::listen(ScheduledTaskFailed::class, static function () use ($metrics): void {
            $metrics->flushCacheMetrics();
        });
    }
}
