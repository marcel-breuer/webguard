<?php

declare(strict_types=1);

return [
    'title' => 'Notifications',
    'status_change_notifications' => 'Status Change',
    'status_board' => [
        'heading' => 'Notification Board',
    ],
    'ssl_expiry_notifications' => 'SSL Expiry',
    'domain_expiry_notifications' => 'Domain Expiry',
    'delivery_history' => [
        'heading' => 'Delivery History',
    ],
    'overview' => [
        'eyebrow' => 'Operations inbox',
        'description' => 'Review monitor incidents, expiry risks, and channel deliveries from one focused command center.',
        'workflow_label' => 'Notification workflow overview',
        'workflow' => [
            'triage' => [
                'label' => 'Triage',
                'title' => 'Status changes',
                'description' => 'Group recoveries and incidents by monitor so the latest state is easy to act on.',
            ],
            'expiry' => [
                'label' => 'Risk',
                'title' => 'Certificate and domain expiry',
                'description' => 'Keep security and ownership deadlines visible before they become outages.',
            ],
            'audit' => [
                'label' => 'Audit',
                'title' => 'Channel delivery',
                'description' => 'Inspect whether Slack, Telegram, Discord, Microsoft Teams, and webhook notifications reached their target.',
            ],
        ],
    ],
    'sections' => [
        'ssl_expiry' => [
            'description' => 'Certificate warnings that need renewal planning or verification.',
        ],
        'domain_expiry' => [
            'description' => 'Domain ownership deadlines and expiry alerts for monitored targets.',
        ],
        'status_change' => [
            'description' => 'Current incident and recovery state per monitor, consolidated for faster triage.',
        ],
        'delivery_history' => [
            'description' => 'Recent delivery attempts across configured outbound notification channels.',
        ],
    ],
    'loading' => [
        'title' => 'Loading notification board',
        'description' => 'Fetching the latest monitor updates and delivery attempts.',
    ],
    'empty_state' => [
        'description' => 'Unread alerts, expiry warnings, and delivery events will appear here as soon as they need attention.',
    ],
    'filters' => [
        'heading' => 'View',
        'unread' => 'Open',
        'all' => 'All',
    ],
    'load_more' => 'Load More',
    'mark_as_read' => 'Mark as Read',
    'mark_all_as_read' => 'Mark all as read',
    'read' => 'Read',
    'no_notifications' => 'Nothing to discover. Everything is up to date.',
    'no_notifications_of_this_type' => 'No notifications of this type.',
    'show_read_notifications' => 'Show Read Notifications',
    'labels' => [
        'monitor' => 'Type',
        'host' => 'Host',
        'timestamp' => 'Latest check',
        'latest_status_change' => 'Latest status change',
        'channel' => 'Channel',
        'event' => 'Event',
        'attempted_at' => 'Attempted at',
        'sent_at' => 'Sent at',
        'error' => 'Error',
        'no_status_code' => 'No status code',
        'not_available' => 'Not available',
    ],
    'tooltips' => [
        'latest_status' => 'Latest status: :status',
    ],
    'status' => [
        'success' => 'Successful',
        'redirect' => 'Redirect',
        'client_error' => 'Client Error',
        'server_error' => 'Server Error',
        'unknown' => 'Unknown',
        'maintenance' => 'Maintenance',
    ],
    'status_change' => [
        'up' => 'Latest status change: monitor recovered.',
        'down' => 'Latest status change: monitor is down.',
        'unknown' => 'Latest status change: status is unknown.',
        'maintenance' => 'Latest status change: monitoring is in maintenance mode.',
    ],
    'status_messages' => [
        'up' => 'Monitoring :name status changed to UP',
        'down' => 'Monitoring :name status changed to DOWN',
    ],
    'ssl_messages' => [
        'expiring' => 'SSL certificate for :name is expiring soon.',
        'expired' => 'SSL certificate for :name has expired.',
    ],
    'domain_messages' => [
        'expiring' => 'Domain :name is expiring soon.',
        'expired' => 'Domain :name has expired.',
    ],
    'performance_messages' => [
        'degraded' => ':name response time at :location is :current_ms ms, :increase_percent% above its :baseline_ms ms historical median.',
        'recovered' => ':name response time at :location recovered to :current_ms ms against its :baseline_ms ms historical median.',
    ],
    'channels' => [
        'slack' => 'Slack',
        'telegram' => 'Telegram',
        'discord' => 'Discord',
        'teams' => 'Microsoft Teams',
        'webhook' => 'Webhook',
        'mobile_push' => 'Mobile Push',
    ],
    'events' => [
        'incident' => 'Incident',
        'recovery' => 'Recovery',
        'ssl_expiring' => 'SSL expiring',
        'ssl_expired' => 'SSL expired',
        'domain_expiring' => 'Domain expiring',
        'domain_expired' => 'Domain expired',
    ],
    'delivery_status' => [
        'sent' => 'Sent',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
    ],
    'messages' => [
        'notification_marked_as_read' => 'Notification marked as read.',
        'all_notifications_marked_as_read' => 'All notifications marked as read.',
    ],
];
