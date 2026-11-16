<?php

declare(strict_types=1);

namespace App\Services\Mcp;

/**
 * Declarative catalogue of MCP tools that map one-to-one to a REST route.
 *
 * `access` is `read` (needs `management:read`) or `write` (needs `management:write`).
 * Every entry names the REST route it executes, so validation and authorization are
 * those of the REST API. Descriptions are written for models and never contain
 * credentials, hostnames, or customer data.
 */
final class McpToolCatalogue
{
    private const WRITE_PREFIX = 'WRITE: ';

    private const DESTRUCTIVE_PREFIX = 'WRITE, DESTRUCTIVE: ';

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $id = static fn (string $what): array => ['type' => 'string', 'minLength' => 1, 'description' => $what];
        $monitoringId = $id('Monitoring ID, as returned by list_monitorings.');
        $statusPageId = $id('Status page ID, as returned by list_status_pages.');
        $incidentId = $id('Incident ID, as returned by list_status_page_incidents or list_open_incidents.');
        $groupId = $id('Monitoring group ID, as returned by list_monitoring_groups.');
        $teamId = $id('Team ID, as returned by list_teams.');
        $paging = [
            'page' => ['type' => 'integer', 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        ];

        return [
            self::read('get_dashboard', 'Get dashboard summary', 'Summarise the monitoring dashboard of the authenticated user: counts per status and recent activity.', 'app.dashboard', schema: []),
            self::read('get_monitoring', 'Get monitoring', 'Return the configuration and current state of one monitoring the user can see. Secrets such as heartbeat tokens are redacted.', 'app.monitorings.show', ['monitoring' => 'monitoring_id'], ['monitoring_id' => $monitoringId], ['monitoring_id']),
            self::read('search_monitorings', 'Search monitorings', 'Search monitorings by name or target with pagination. The search term must have at least two characters.', 'app.monitorings.search', schema: ['search' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120, 'description' => 'Search term.']], required: ['search']),
            self::read('get_monitoring_notification_preferences', 'Get monitoring notification preferences', 'Return which of the user\'s notification channels fire for one monitoring.', 'app.monitorings.notification-preferences.show', ['monitoring' => 'monitoring_id'], ['monitoring_id' => $monitoringId], ['monitoring_id']),
            self::read('list_monitoring_groups', 'List monitoring groups', 'List the user\'s monitoring groups.', 'app.monitoring-groups.index', schema: []),
            self::read('get_monitoring_group', 'Get monitoring group', 'Return one monitoring group with its monitorings.', 'app.monitoring-groups.show', ['monitoringGroup' => 'group_id'], ['group_id' => $groupId], ['group_id']),
            self::read('list_status_pages', 'List status pages', 'List the status pages the user can manage, with publication state.', 'app.status-pages.index', schema: $paging),
            self::read('get_status_page', 'Get status page', 'Return one status page with its components and publication settings.', 'app.status-pages.show', ['statusPage' => 'status_page_id'], ['status_page_id' => $statusPageId], ['status_page_id']),
            self::read('list_status_page_incidents', 'List status page incidents', 'List incidents that affect a status page, newest first.', 'app.status-pages.incidents.index', ['statusPage' => 'status_page_id'], ['status_page_id' => $statusPageId] + $paging, ['status_page_id']),
            self::read('get_status_page_incident', 'Get status page incident', 'Return one incident of a status page with updates, review, follow-ups and timeline.', 'app.status-pages.incidents.show', ['statusPage' => 'status_page_id', 'incident' => 'incident_id'], ['status_page_id' => $statusPageId, 'incident_id' => $incidentId], ['status_page_id', 'incident_id']),
            self::read('list_one_off_maintenance', 'List one-off maintenance', 'List scheduled and running one-off maintenance windows.', 'app.maintenance.one-off.index', schema: $paging),
            self::read('list_recurring_maintenance', 'List recurring maintenance', 'List recurring maintenance windows.', 'app.maintenance.recurring.index', schema: $paging),
            self::read('list_teams', 'List teams', 'List the teams the user belongs to with their role.', 'app.teams.index', schema: []),
            self::read('get_team', 'Get team', 'Return one team with members and pending invitations.', 'app.teams.show', ['team' => 'team_id'], ['team_id' => $teamId], ['team_id']),
            self::read('get_notification_settings', 'Get notification settings', 'Return which notification channels are enabled and the digest settings. Webhook URLs and bot credentials are never returned; configure them in the WebGuard UI.', 'app.profile.notification-settings.show', schema: []),
            self::read('list_notifications', 'List notifications', 'List the in-app notification inbox of the user.', 'app.notifications.index', schema: $paging),
            self::read('get_incident_analytics', 'Get incident analytics', 'Aggregate incident statistics (by type, severity, recurrence, recovery time). Optional filters are passed through to the API.', 'app.incidents.analytics', schema: [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
            ]),

            self::write('create_monitoring', 'Create monitoring', 'Create a monitoring. Required: name, type (http, ping, keyword, port, heartbeat, server_health, domain_expiration, dns_record), status (active or paused), preferred_location and preferred_locations (location codes), plus the fields of the chosen type such as target. Package limits and validation apply.', 'POST', 'app.monitorings.store', schema: [
                'name' => ['type' => 'string', 'maxLength' => 255],
                'type' => ['type' => 'string', 'enum' => ['http', 'ping', 'keyword', 'port', 'heartbeat', 'server_health', 'domain_expiration', 'dns_record']],
                'target' => ['type' => 'string', 'description' => 'URL, hostname or IP address, depending on type.'],
                'status' => ['type' => 'string', 'enum' => ['active', 'paused']],
                'preferred_location' => ['type' => 'string'],
                'preferred_locations' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                'team_id' => ['type' => ['string', 'null'], 'description' => 'Create the monitoring for a team the user administers.'],
            ], required: ['name', 'type', 'status', 'preferred_location', 'preferred_locations'], additional: true),
            self::write('update_monitoring', 'Update monitoring', 'Replace the configuration of a monitoring. The API requires the complete definition (name, type, status, locations and the fields of its type), so call get_monitoring first and send the full object with your changes. Set status to paused or active to pause or resume checks.', 'PATCH', 'app.monitorings.update', ['monitoring' => 'monitoring_id'], [
                'monitoring_id' => $monitoringId,
                'name' => ['type' => 'string', 'maxLength' => 255],
                'type' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['active', 'paused']],
                'preferred_location' => ['type' => 'string'],
                'preferred_locations' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], ['monitoring_id', 'name', 'type', 'status', 'preferred_location', 'preferred_locations'], additional: true, idempotent: true),
            self::write('delete_monitoring', 'Delete monitoring', 'Delete a monitoring (soft delete). Checks stop immediately.', 'DELETE', 'app.monitorings.destroy', ['monitoring' => 'monitoring_id'], ['monitoring_id' => $monitoringId], ['monitoring_id'], destructive: true, idempotent: true),
            self::write('reset_monitoring_results', 'Reset monitoring results', 'Permanently delete the stored check results and incidents of a monitoring. This cannot be undone.', 'DELETE', 'app.monitorings.results.destroy', ['monitoring' => 'monitoring_id'], ['monitoring_id' => $monitoringId], ['monitoring_id'], destructive: true, idempotent: true),
            self::write('update_monitoring_notification_preferences', 'Update monitoring notification preferences', 'Choose which of the user\'s notification channels fire for one monitoring.', 'PATCH', 'app.monitorings.notification-preferences.update', ['monitoring' => 'monitoring_id'], [
                'monitoring_id' => $monitoringId,
                'notification_on_failure' => ['type' => 'boolean'],
                'notification_channels' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], ['monitoring_id'], additional: true, idempotent: true),
            self::write('create_monitoring_group', 'Create monitoring group', 'Create a monitoring group.', 'POST', 'app.monitoring-groups.store', schema: [
                'name' => ['type' => 'string', 'maxLength' => 100],
                'description' => ['type' => ['string', 'null']],
                'sla_report_enabled' => ['type' => 'boolean'],
                'sla_target_percentage' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 100],
                'monitoring_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100],
            ], required: ['name']),
            self::write('update_monitoring_group', 'Update monitoring group', 'Update a monitoring group.', 'PATCH', 'app.monitoring-groups.update', ['monitoringGroup' => 'group_id'], [
                'group_id' => $groupId,
                'name' => ['type' => 'string', 'maxLength' => 100],
                'description' => ['type' => ['string', 'null']],
                'sla_report_enabled' => ['type' => 'boolean'],
                'sla_target_percentage' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 100],
                'monitoring_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100],
            ], ['group_id'], idempotent: true),
            self::write('delete_monitoring_group', 'Delete monitoring group', 'Delete a monitoring group. The monitorings in it are kept.', 'DELETE', 'app.monitoring-groups.destroy', ['monitoringGroup' => 'group_id'], ['group_id' => $groupId], ['group_id'], destructive: true, idempotent: true),
            self::write('create_status_page', 'Create status page', 'Create a status page. Each component is a named set of monitorings (source_type manual with monitoring_ids) or a monitoring group (source_type monitoring_group with monitoring_group_id).', 'POST', 'app.status-pages.store', schema: self::statusPageSchema(), required: ['name', 'components']),
            self::write('update_status_page', 'Update status page', 'Replace the settings and components of a status page. Send the complete definition.', 'PATCH', 'app.status-pages.update', ['statusPage' => 'status_page_id'], ['status_page_id' => $statusPageId] + self::statusPageSchema(), ['status_page_id', 'name', 'components'], idempotent: true),
            self::write('delete_status_page', 'Delete status page', 'Delete a status page and its public URL.', 'DELETE', 'app.status-pages.destroy', ['statusPage' => 'status_page_id'], ['status_page_id' => $statusPageId], ['status_page_id'], destructive: true, idempotent: true),
            self::write('set_status_page_publication', 'Publish or unpublish status page', 'Make a status page public or private. Publishing makes its content visible to anyone with the link.', 'PATCH', 'app.status-pages.publication.update', ['statusPage' => 'status_page_id'], [
                'status_page_id' => $statusPageId,
                'is_public' => ['type' => 'boolean'],
            ], ['status_page_id', 'is_public'], idempotent: true),
            self::write('create_status_page_announcement', 'Create status page announcement', 'Publish an announcement on a status page. With notify_subscribers true, verified subscribers receive an email.', 'POST', 'app.status-pages.announcements.store', ['statusPage' => 'status_page_id'], [
                'status_page_id' => $statusPageId,
                'title' => ['type' => 'string', 'maxLength' => 120],
                'message' => ['type' => 'string', 'maxLength' => 2000],
                'notify_subscribers' => ['type' => 'boolean'],
            ], ['status_page_id', 'title', 'message', 'notify_subscribers'], openWorld: true),
            self::write('dismiss_status_page_announcement', 'Dismiss status page announcement', 'Remove an announcement from a status page.', 'DELETE', 'app.status-pages.announcements.dismiss', ['statusPage' => 'status_page_id', 'announcement' => 'announcement_id'], [
                'status_page_id' => $statusPageId,
                'announcement_id' => $id('Announcement ID.'),
            ], ['status_page_id', 'announcement_id'], destructive: true, idempotent: true),
            self::write('post_incident_update', 'Post incident update', 'Publish a status update on an incident of a status page. Verified subscribers may be emailed. Never include internal notes or credentials.', 'POST', 'app.status-pages.incidents.updates.store', ['statusPage' => 'status_page_id', 'incident' => 'incident_id'], [
                'status_page_id' => $statusPageId,
                'incident_id' => $incidentId,
                'status' => ['type' => 'string', 'enum' => ['investigating', 'identified', 'monitoring', 'resolved']],
                'message' => ['type' => 'string', 'maxLength' => 2000],
                'idempotency_key' => self::idempotencyKeyProperty(),
            ], ['status_page_id', 'incident_id', 'status', 'message'], openWorld: true, idempotencyKey: true),
            self::write('schedule_maintenance', 'Schedule maintenance', 'Schedule a one-off or recurring maintenance window for a monitoring or a monitoring group. Alerts are suppressed while it runs.', 'POST', 'app.maintenance.store', schema: [
                'mode' => ['type' => 'string', 'enum' => ['one_off', 'recurring']],
                'scope' => ['type' => 'string', 'enum' => ['monitoring', 'group']],
                'monitoring_id' => ['type' => ['string', 'null']],
                'monitoring_group_id' => ['type' => ['string', 'null']],
                'maintenance_from' => ['type' => ['string', 'null'], 'description' => 'ISO 8601 start, for one_off.'],
                'maintenance_until' => ['type' => ['string', 'null'], 'description' => 'ISO 8601 end, for one_off.'],
                'recurring_starts_at' => ['type' => ['string', 'null']],
                'recurring_duration_minutes' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 1440],
                'recurrence' => ['type' => ['string', 'null'], 'enum' => ['weekly', 'monthly', null]],
                'recurring_repeat_until' => ['type' => ['string', 'null']],
                'recurring_timezone' => ['type' => ['string', 'null'], 'description' => 'IANA timezone such as Europe/Berlin.'],
                'idempotency_key' => self::idempotencyKeyProperty(),
            ], required: ['mode', 'scope'], idempotencyKey: true),
            self::write('cancel_one_off_maintenance', 'Cancel one-off maintenance', 'Cancel the scheduled one-off maintenance of a monitoring.', 'DELETE', 'app.maintenance.one-off.destroy', ['monitoring' => 'monitoring_id'], ['monitoring_id' => $monitoringId], ['monitoring_id'], destructive: true, idempotent: true),
            self::write('create_team', 'Create team', 'Create a team. The caller becomes its admin.', 'POST', 'app.teams.store', schema: [
                'name' => ['type' => 'string', 'maxLength' => 120],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 1000],
            ], required: ['name']),
            self::write('invite_team_member', 'Invite team member', 'Invite a person to a team by email. Sends an invitation email.', 'POST', 'app.teams.invitations.store', ['team' => 'team_id'], [
                'team_id' => $teamId,
                'email' => ['type' => 'string', 'format' => 'email'],
                'role' => ['type' => 'string', 'enum' => ['admin', 'member']],
            ], ['team_id', 'email', 'role'], openWorld: true),
            self::write('send_notification_channel_test', 'Send test notification', 'Send a test message through one of the user\'s configured notification channels (email, slack, discord, teams, telegram, webhook, mobile_push). This contacts the external provider.', 'POST', 'app.profile.notification-settings.test', ['channel' => 'channel'], [
                'channel' => ['type' => 'string', 'enum' => ['email', 'slack', 'discord', 'teams', 'telegram', 'webhook', 'mobile_push']],
            ], ['channel'], openWorld: true),
            self::write('mark_notification_read', 'Mark notification read', 'Mark one in-app notification as read.', 'PATCH', 'app.notifications.read', ['notification' => 'notification_id'], ['notification_id' => $id('Notification ID.')], ['notification_id'], idempotent: true),
            self::write('mark_all_notifications_read', 'Mark all notifications read', 'Mark every in-app notification as read.', 'PATCH', 'app.notifications.read-all', schema: [], idempotent: true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function idempotencyKeyProperty(): array
    {
        return ['type' => 'string', 'maxLength' => 100, 'description' => 'Optional. Reuse the same key when retrying this call so the operation is applied only once; a fresh key is generated when omitted.'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function statusPageSchema(): array
    {
        return [
            'name' => ['type' => 'string', 'maxLength' => 255],
            'description' => ['type' => ['string', 'null'], 'maxLength' => 1000],
            'is_public' => ['type' => 'boolean'],
            'auto_publish_incident_updates' => ['type' => 'boolean'],
            'components' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'maxLength' => 255],
                        'description' => ['type' => ['string', 'null']],
                        'source_type' => ['type' => 'string', 'enum' => ['manual', 'monitoring_group']],
                        'monitoring_group_id' => ['type' => ['string', 'null']],
                        'monitoring_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['name', 'source_type'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $path
     * @param  array<string, array<string, mixed>>  $schema
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function read(string $name, string $title, string $description, string $route, array $path = [], array $schema = [], array $required = []): array
    {
        return [
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'access' => 'read',
            'method' => 'GET',
            'route' => $route,
            'path' => $path,
            'schema' => $schema,
            'required' => $required,
            'additional' => false,
            'destructive' => false,
            'idempotent' => true,
            'openWorld' => false,
            'idempotencyKey' => false,
        ];
    }

    /**
     * @param  array<string, string>  $path
     * @param  array<string, array<string, mixed>>  $schema
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function write(string $name, string $title, string $description, string $method, string $route, array $path = [], array $schema = [], array $required = [], bool $additional = false, bool $destructive = false, bool $idempotent = false, bool $openWorld = false, bool $idempotencyKey = false): array
    {
        return [
            'name' => $name,
            'title' => $title,
            'idempotencyKey' => $idempotencyKey,
            'description' => ($destructive ? self::DESTRUCTIVE_PREFIX : self::WRITE_PREFIX) . $description,
            'access' => 'write',
            'method' => $method,
            'route' => $route,
            'path' => $path,
            'schema' => $schema,
            'required' => $required,
            'additional' => $additional,
            'destructive' => $destructive,
            'idempotent' => $idempotent,
            'openWorld' => $openWorld,
        ];
    }
}
