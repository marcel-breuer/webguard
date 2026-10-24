<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Monitoring Interval
    |--------------------------------------------------------------------------
    |
    | HTTP and keyword monitorings store a customer-selected interval. This
    | default is used only when older records or internal callers have no saved
    | value. Other active checks retain their existing default cadence.
    |
    */
    'website_interval_minutes' => 15,
    'default_interval_minutes' => (int) env('MONITORING_DEFAULT_INTERVAL_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Regional Consensus
    |--------------------------------------------------------------------------
    |
    | Multi-location consensus considers only recent reports. A strict majority
    | must report a failure before WebGuard opens an incident.
    |
    */
    'regional_consensus_freshness_minutes' => (int) env('MONITORING_REGIONAL_CONSENSUS_FRESHNESS_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Heartbeat Queue Name
    |--------------------------------------------------------------------------
    |
    | Missed heartbeat evaluation is dispatched onto a dedicated queue so it
    | can be processed separately from the default application workload while
    | still using the standard Redis queue connection.
    |
    */
    'heartbeat_queue' => env('HEARTBEAT_QUEUE', 'heartbeat'),

    /*
    |--------------------------------------------------------------------------
    | Server Instance Health
    |--------------------------------------------------------------------------
    |
    | Internal scanner instances update their last-seen timestamp after a
    | successful API authentication. Writes are throttled to avoid updating on
    | every polling request, while stale_after_minutes controls the admin health
    | indicator for active instances.
    |
    */
    'instance_seen_write_throttle_seconds' => (int) env('MONITORING_INSTANCE_SEEN_WRITE_THROTTLE_SECONDS', 60),
    'instance_stale_after_minutes' => (int) env('MONITORING_INSTANCE_STALE_AFTER_MINUTES', 10),
    'instance_never_seen_alert_after_minutes' => (int) env('MONITORING_INSTANCE_NEVER_SEEN_ALERT_AFTER_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Weekly Digest
    |--------------------------------------------------------------------------
    |
    | SSL certificates and domains expiring within this window are included in
    | the weekly monitoring digest so customers can act before critical expiry.
    |
    */
    'digest_expiry_warning_days' => (int) env('MONITORING_DIGEST_EXPIRY_WARNING_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Expiry Warning Windows
    |--------------------------------------------------------------------------
    |
    | Users can choose the threshold at which one SSL or domain expiry warning
    | is sent. The default keeps a seven-day warning enabled for existing users.
    |
    */
    'expiry_warning_days' => [
        'allowed' => [30, 14, 7, 3, 1],
        'default' => [7],
    ],
];
