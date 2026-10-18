<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/**
 * =================================================================
 * NOTIFICATIONS
 * =================================================================
 */
// Dispatch status change notifications immediately.
Schedule::command('notifications:dispatch-status-changes')->everyMinute()->withoutOverlapping();

Schedule::command('notifications:dispatch-planned-maintenance')->everyMinute()->withoutOverlapping();

// Remind users daily about unread notifications in their board.
Schedule::command('notifications:remind-unread-weekly')->dailyAt('08:00')->withoutOverlapping();

// Send customers their configured monitoring performance digest.
Schedule::command('notifications:send-weekly-monitoring-digest')->dailyAt('08:30')->withoutOverlapping();

// Alert admins when scanner instances stop reporting or recover.
Schedule::command('notifications:send-server-instance-health-alerts')->everyFiveMinutes()->withoutOverlapping();

// Prune old read notifications daily.
Schedule::command('notifications:prune-read')->dailyAt('01:00');

// Prune old demo user notifications daily.
Schedule::command('notifications:prune-demo')
    ->dailyAt('01:30')
    ->withoutOverlapping();

// Prune expired instance callback idempotency records daily.
Schedule::command('instances:prune-callback-idempotency')->dailyAt('02:45')->withoutOverlapping();

/**
 * =================================================================
 * MONITORING & DATA MANAGEMENT
 * =================================================================
 */
// Prune audit log entries older than one month.
Schedule::command('activitylog:clean --days=30 --force')->dailyAt('02:30')->withoutOverlapping();

// Aggregate raw monitoring data into daily summaries.
Schedule::command('monitoring:aggregate-daily')->dailyAt('00:30');

// Evaluate passive heartbeat monitorings for missed pings.
Schedule::command('monitoring:evaluate-heartbeats')->everyMinute()->withoutOverlapping();

// Evaluate passive server-health monitorings for stale reports.
Schedule::command('monitoring:evaluate-server-health')->everyMinute()->withoutOverlapping();

// Archive old monitoring responses weekly.
Schedule::command('monitoring:archive-responses')->weekly();

// Check for expiring SSL certificates and domains daily.
Schedule::command('notifications:send-ssl-expiry-warnings')->dailyAt('06:00')->withoutOverlapping();

// Permanently delete soft-deleted monitorings and their data monthly.
Schedule::command('monitoring:purge-soft-deleted')->monthly()->withoutOverlapping();
