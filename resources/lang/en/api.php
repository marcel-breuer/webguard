<?php

declare(strict_types=1);

return [
    'title' => 'API',
    'text' => 'Manage your API keys and access tokens for secure API interactions.',
    'configuration' => [
        'heading' => 'API Configuration',
        'description' => 'Create separate least-privilege keys for each integration. A key is shown once, then only its non-sensitive metadata remains available.',
        'fields' => [
            'token' => 'Your API token',
            'name' => 'Key name',
            'abilities' => 'Permissions',
            'last_used_at' => 'Last used',
            'status' => 'Status',
        ],
        'actions' => [
            'create_key' => 'Create API key',
            'copy' => 'Copy',
            'revoke_key' => 'Revoke key',
        ],
        'messages' => [
            'copied' => 'API Key copied to clipboard!',
            'confirm_revoke_key' => 'Are you sure you want to revoke this API key? Integrations that use it will stop working immediately.',
            'created' => 'API key created. Copy it now: it will not be shown again.',
            'revoked' => 'API key revoked.',
            'api_key_confidential_warning' => 'Keep your API key confidential. If you believe your key has been compromised, you can generate a new one.',
        ],
        'abilities' => [
            'server_health_write' => 'Server Health write — submit telemetry only',
            'analytics_read' => 'Analytics read — access supported reporting endpoints only',
            'management_read' => 'Management read — read monitorings, status pages, incidents, maintenance and teams',
            'management_write' => 'Management write — create, change and delete those resources',
        ],
        'active' => 'Active',
        'revoked' => 'Revoked',
        'never_used' => 'Never',
    ],
    'logs' => [
        'title' => 'API Logs',
        'description' => 'View your API usage logs to monitor requests and responses.',
        'fields' => [
            'date' => 'Date',
            'email' => 'Email',
            'endpoint' => 'Endpoint',
        ],
        'messages' => [
            'no_logs' => 'No API logs found.',
        ],
    ],
    'docs' => [
        'heading' => 'API Reference',
        'description' => 'Review the monitoring endpoints, authentication flow, and request examples for your integrations.',
        'link' => 'Open the API reference.',
    ],
];
