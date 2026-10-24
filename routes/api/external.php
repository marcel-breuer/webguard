<?php

declare(strict_types=1);

use App\Http\Controllers\Api\External\MobileMaintenanceController;
use App\Http\Controllers\Api\External\MobileMonitoringDetailController;
use App\Http\Controllers\Api\External\MobileMonitoringGroupController;
use App\Http\Controllers\Api\External\MobileMonitoringNotificationPreferenceController;
use App\Http\Controllers\Api\External\MobileNotificationBoardController;
use App\Http\Controllers\Api\External\MobileOverviewController;
use App\Http\Controllers\Api\External\MobilePushDeviceController;
use App\Http\Controllers\Api\External\MobileStatusPageWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/overview', MobileOverviewController::class)
    ->name('overview');
Route::get('/monitorings/{monitoring}', MobileMonitoringDetailController::class)
    ->name('monitorings.show');
Route::get('/monitorings/{monitoring}/notification-preferences', [MobileMonitoringNotificationPreferenceController::class, 'show'])
    ->name('monitorings.notification-preferences.show');
Route::patch('/monitorings/{monitoring}/notification-preferences', [MobileMonitoringNotificationPreferenceController::class, 'update'])
    ->name('monitorings.notification-preferences.update');

Route::get('/notification-board', [MobileNotificationBoardController::class, 'index'])
    ->name('notification-board.index');
Route::patch('/notification-board/{notification}/read', [MobileNotificationBoardController::class, 'markRead'])
    ->name('notification-board.read');
Route::patch('/notification-board/read-all', [MobileNotificationBoardController::class, 'markAllRead'])
    ->name('notification-board.read-all');

Route::group(['prefix' => 'maintenance', 'as' => 'maintenance.'], function (): void {
    Route::get('/capabilities', [MobileMaintenanceController::class, 'capabilities'])->name('capabilities');
    Route::get('/one-off', [MobileMaintenanceController::class, 'oneOffIndex'])->name('one-off.index');
    Route::get('/recurring', [MobileMaintenanceController::class, 'recurringIndex'])->name('recurring.index');
    Route::post('/', [MobileMaintenanceController::class, 'store'])->name('store');
    Route::patch('/recurring/{maintenanceWindow}', [MobileMaintenanceController::class, 'updateRecurring'])->name('recurring.update');
    Route::delete('/one-off/{monitoring}', [MobileMaintenanceController::class, 'cancelOneOff'])->name('one-off.destroy');
});

Route::group(['prefix' => 'monitoring-groups', 'as' => 'monitoring-groups.'], function (): void {
    Route::get('/', [MobileMonitoringGroupController::class, 'index'])->name('index');
    Route::get('/assignment-options', [MobileMonitoringGroupController::class, 'assignmentOptions'])->name('assignment-options');
    Route::post('/', [MobileMonitoringGroupController::class, 'store'])->name('store');
    Route::get('/{monitoringGroup}', [MobileMonitoringGroupController::class, 'show'])->name('show');
    Route::patch('/{monitoringGroup}', [MobileMonitoringGroupController::class, 'update'])->name('update');
    Route::delete('/{monitoringGroup}', [MobileMonitoringGroupController::class, 'destroy'])->name('destroy');
});

Route::group(['prefix' => 'status-pages', 'as' => 'status-pages.'], function (): void {
    Route::get('/', [MobileStatusPageWorkspaceController::class, 'index'])->name('index');
    Route::get('/{statusPage}', [MobileStatusPageWorkspaceController::class, 'show'])->name('show');
    Route::patch('/{statusPage}/publication', [MobileStatusPageWorkspaceController::class, 'updatePublication'])->name('publication.update');
    Route::get('/{statusPage}/incidents', [MobileStatusPageWorkspaceController::class, 'incidents'])->name('incidents.index');
    Route::get('/{statusPage}/incidents/{incident}', [MobileStatusPageWorkspaceController::class, 'showIncident'])->name('incidents.show');
    Route::post('/{statusPage}/incidents/{incident}/updates', [MobileStatusPageWorkspaceController::class, 'storeIncidentUpdate'])->name('incidents.updates.store');
    Route::patch('/{statusPage}/incidents/{incident}/updates/{incidentUpdate}', [MobileStatusPageWorkspaceController::class, 'updateIncidentUpdate'])->name('incidents.updates.update');
    Route::delete('/{statusPage}/incidents/{incident}/updates/{incidentUpdate}', [MobileStatusPageWorkspaceController::class, 'suppressIncidentUpdate'])->name('incidents.updates.suppress');
    Route::patch('/{statusPage}/incidents/{incident}/metadata', [MobileStatusPageWorkspaceController::class, 'updateMetadata'])->name('incidents.metadata.update');
    Route::patch('/{statusPage}/incidents/{incident}/review', [MobileStatusPageWorkspaceController::class, 'updateReview'])->name('incidents.review.update');
    Route::post('/{statusPage}/incidents/{incident}/follow-ups', [MobileStatusPageWorkspaceController::class, 'storeFollowUp'])->name('incidents.follow-ups.store');
    Route::patch('/{statusPage}/incidents/{incident}/follow-ups/{incidentFollowUp}', [MobileStatusPageWorkspaceController::class, 'updateFollowUp'])->name('incidents.follow-ups.update');
    Route::delete('/{statusPage}/incidents/{incident}/follow-ups/{incidentFollowUp}', [MobileStatusPageWorkspaceController::class, 'destroyFollowUp'])->name('incidents.follow-ups.destroy');
    Route::post('/{statusPage}/incidents/{incident}/timeline', [MobileStatusPageWorkspaceController::class, 'storeTimelineEvent'])->name('incidents.timeline.store');
    Route::patch('/{statusPage}/incidents/{incident}/timeline/{incidentTimelineEvent}', [MobileStatusPageWorkspaceController::class, 'updateTimelineEvent'])->name('incidents.timeline.update');
    Route::delete('/{statusPage}/incidents/{incident}/timeline/{incidentTimelineEvent}', [MobileStatusPageWorkspaceController::class, 'destroyTimelineEvent'])->name('incidents.timeline.destroy');
});

Route::apiResource('push-devices', MobilePushDeviceController::class)
    ->parameters(['push-devices' => 'mobilePushDevice'])
    ->only(['index', 'store', 'update', 'destroy']);
