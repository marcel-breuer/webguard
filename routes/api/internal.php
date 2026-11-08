<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MaintenanceDataController;
use App\Http\Controllers\Api\Monitoring\MonitoringAvailabilityController;
use App\Http\Controllers\Api\Monitoring\MonitoringDashboardController;
use App\Http\Controllers\Api\Monitoring\MonitoringPerformanceController;
use Illuminate\Support\Facades\Route;

Route::get('/maintenance', MaintenanceDataController::class)->name('maintenance.index');

Route::group(['prefix' => 'monitorings/{monitoring}', 'as' => 'monitorings.analytics.'], function (): void {
    Route::get('/analytics', [MonitoringDashboardController::class, 'all'])->name('all');
    Route::get('/status', [MonitoringDashboardController::class, 'status'])->name('status');
    Route::get('/uptime-downtime', [MonitoringAvailabilityController::class, 'uptimeDowntime'])->name('uptime-downtime');
    Route::get('/uptime-downtime-summary', [MonitoringAvailabilityController::class, 'uptimeDowntimeSummary'])->name('uptime-downtime-summary');
    Route::get('/response-times', [MonitoringPerformanceController::class, 'responseTimes'])->name('response-times');
    Route::get('/server-health-telemetry', [MonitoringPerformanceController::class, 'serverHealthTelemetry'])->name('server-health-telemetry');
    Route::get('/checks', [MonitoringPerformanceController::class, 'checks'])->name('checks');
    Route::get('/incidents', [MonitoringDashboardController::class, 'incidents'])->name('incidents');
    Route::get('/heatmap', [MonitoringAvailabilityController::class, 'uptimeHeatmap'])->name('heatmap');
    Route::get('/ssl', [MonitoringDashboardController::class, 'sslStatus'])->name('ssl');
    Route::get('/uptime-calendar', [MonitoringAvailabilityController::class, 'uptimeCalendar'])->name('uptime-calendar');
});
