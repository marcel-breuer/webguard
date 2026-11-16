# REST API guide

WebGuard exposes the same resources to the web interface, to scripts, and to AI agents through one
authenticated REST API under `/api`. Everything a user can do in the management UI is available
here, using the same controllers, validation, and authorization as the UI. The machine-readable
contract is [`docs/api/openapi.json`](openapi.json) (OpenAPI 3.1). The Model Context Protocol server
in [`docs/mcp.md`](../mcp.md) is a thin layer on top of these routes.

This guide covers the token-accessible API. Related contracts are documented separately:

- [Shared API contract and compatibility policy](external-v1.md), including the native mobile API.
- [Scanner instance API](../integrations/webguard-instance-api.md) and
  [server agent API](../integrations/server-agent-api.md).
- The generated public reference at `/api/reference` for unauthenticated badge and status endpoints.

## Authentication

Send a bearer token on every request:

```bash
curl -H "Authorization: Bearer $WEBGUARD_API_KEY" -H "Accept: application/json" \
  "$WEBGUARD_URL/api/monitorings?per_page=10"
```

Create an API key in **Profile > API keys** (or `POST /api/profile/api-keys` from a browser session).
The plaintext key is shown exactly once. Bearer requests do not need a CSRF token; browser sessions
keep full CSRF protection.

| Ability | Grants |
| --- | --- |
| `management:read` | `GET` on monitorings, monitoring groups, status pages, incidents, maintenance, teams, notifications, notification settings, and the dashboard. |
| `management:write` | `POST`, `PUT`, `PATCH`, and `DELETE` on the same resources. Does not include read access: grant both for full automation. |
| `analytics:read` | Only the read-only monitoring analytics routes (`/api/monitorings/{monitoring}/analytics`, `status`, `uptime-downtime`, `response-times`, `checks`, `incidents`, `heatmap`, `ssl`, `uptime-calendar`) and the MCP analytics tools. |
| `server-health:write` | Only the bearer server health ingestion endpoint. |

Scoped keys can never reach account, session, API key, administration, or mobile routes
(`unscoped` in the endpoint tables below). Those remain available to browser sessions and legacy
personal access tokens, subject to the same role checks as the UI (for example `role:admin`). This
is the documented exclusion that stops a leaked automation key from widening its own permissions.

Legacy personal access tokens keep working unchanged. Their abilities are only enforced when
`EXTERNAL_API_ENFORCE_TOKEN_ABILITIES=true` (`external:read` for safe methods, `external:write`
otherwise). Mobile app tokens are accepted only on `/api/mobile/*`.

## Authorization

Resource access is identical to the UI. A key acts as its owner: private monitorings of other users
are `404`, team resources follow the caller's team role, demo accounts are read-only, and package
limits apply when creating monitorings. Authorization never depends on how the request was made.

## Conventions

- **Format.** JSON in, JSON out. Send `Accept: application/json`; errors under `/api/*` are rendered
  as JSON even without it.
- **Resource naming.** Plural, kebab-case collections (`/api/status-pages`), identifiers in the path,
  sub-resources nested (`/api/status-pages/{id}/incidents/{incident}/updates`). The path set is the
  `v1` contract; changes are additive (see the [compatibility policy](external-v1.md)).
- **Methods.** `GET` reads, `POST` creates or triggers, `PATCH` changes part of a resource, `PUT`
  replaces, `DELETE` removes. Monitoring and status page updates validate the complete definition,
  so send all required fields.
- **Pagination.** Collections that can grow are paginated with `page` and `per_page` (1 to 100,
  default 25) and return `data` plus pagination `meta`. Check history uses `limit` (1 to 1,000).
  Small bounded collections (teams, memberships, invitations, push devices) return the complete
  `data` array. Ordering is deterministic (`name` then `id`, or newest first for history).
- **Filtering.** `GET /api/monitorings` accepts `search` and `lifecycle_status` (`active`, `paused`).
  `GET /api/monitorings/search` accepts a `search` term of at least two characters.
- **Dates.** ISO 8601 with an offset, for example `2026-12-01T22:00:00+01:00`.

### Errors

| Status | Meaning | Body |
| --- | --- | --- |
| `401` | Missing, invalid, expired, or revoked token. | `{"message": "Unauthenticated."}` |
| `403` | The key lacks the ability, the role is insufficient, or the account is read-only. | `{"message": "..."}` |
| `404` | Not found, or not visible to the caller. | `{"message": "..."}` |
| `422` | Validation failed. | `{"message": "...", "errors": {"field": ["..."]}}` |
| `429` | Rate limit exceeded. | `{"message": "Too many requests."}` with `Retry-After` |

Every authenticated response carries `X-Request-Id`; quote it when reporting a problem. Error bodies
never contain tokens, credentials, or stack traces.

### Rate limiting

Each user may send `EXTERNAL_API_RATE_LIMIT_PER_MINUTE` requests per 60 seconds (default `60`) with
personal access tokens and API keys. Responses include `X-RateLimit-Limit`, `X-RateLimit-Remaining`,
and `X-RateLimit-Reset`; a limited request returns `429` and `Retry-After`. MCP tool calls dispatch
through these routes and share the budget. The MCP endpoint itself additionally allows 60 protocol
requests per minute per caller.

### Idempotency

`GET`, `PUT`, `PATCH`, and `DELETE` are safe to retry. Creating maintenance windows and posting
incident updates, follow-ups, and timeline events require an `Idempotency-Key` header (at most 100
characters); repeating a request with the same key returns the original result instead of creating a
duplicate (the first request returns `201`, a retry returns the original resource with `200`; reusing
a key for a different payload is a `422`). Other `POST` endpoints do not accept a key, so do not blindly retry them after an unknown
outcome.

## Examples

Create a monitoring group, then a status page that publishes it:

```bash
curl -X POST "$WEBGUARD_URL/api/monitoring-groups" \
  -H "Authorization: Bearer $WEBGUARD_API_KEY" -H "Content-Type: application/json" \
  -d '{"name": "Production", "monitoring_ids": []}'
# 201 {"data": {"id": "...", "name": "Production", ...}}

curl -X POST "$WEBGUARD_URL/api/status-pages" \
  -H "Authorization: Bearer $WEBGUARD_API_KEY" -H "Content-Type: application/json" \
  -d '{"name": "Acme status", "is_public": false, "components": [
        {"name": "Website", "source_type": "monitoring_group", "monitoring_group_id": "<group id>"}]}'
```

Schedule a one-off maintenance window (idempotent through the header):

```bash
curl -X POST "$WEBGUARD_URL/api/maintenance" \
  -H "Authorization: Bearer $WEBGUARD_API_KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 5f0c6a4e-2d1b-4c11-9a77-0d1f6a2c9d01" \
  -d '{"mode": "one_off", "scope": "monitoring", "monitoring_id": "<id>",
       "maintenance_from": "2026-12-01T22:00:00+01:00", "maintenance_until": "2026-12-01T23:00:00+01:00"}'
```

A validation error:

```json
{"message": "The name field is required.", "errors": {"name": ["The name field is required."]}}
```

## Endpoint reference

Each operation below is part of `openapi.json`, which also lists path and query parameters, request
fields derived from the validation rules, and error responses. The **Ability** column names the
scoped key ability that is required; `unscoped` routes need a browser session or legacy token, and
`none` routes are public.

#### `monitorings`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/monitorings` | `management:read` | `app.monitorings.index` |
| `POST` | `/api/monitorings` | `management:write` | `app.monitorings.store` |
| `GET` | `/api/monitorings/cards` | `management:read` | `app.monitorings.cards` |
| `GET` | `/api/monitorings/form-options` | `management:read` | `app.monitorings.form-options` |
| `GET` | `/api/monitorings/search` | `management:read` | `app.monitorings.search` |
| `DELETE` | `/api/monitorings/{monitoring}` | `management:write` | `app.monitorings.destroy` |
| `GET` | `/api/monitorings/{monitoring}` | `management:read` | `app.monitorings.show` |
| `PATCH` | `/api/monitorings/{monitoring}` | `management:write` | `app.monitorings.update` |
| `GET` | `/api/monitorings/{monitoring}/analytics` | `analytics:read|management:read` | `app.monitorings.analytics.all` |
| `GET` | `/api/monitorings/{monitoring}/checks` | `analytics:read|management:read` | `app.monitorings.analytics.checks` |
| `GET` | `/api/monitorings/{monitoring}/detail-data` | `management:read` | `app.monitorings.detail-data` |
| `GET` | `/api/monitorings/{monitoring}/form-options` | `management:read` | `app.monitorings.form-options.edit` |
| `GET` | `/api/monitorings/{monitoring}/heatmap` | `analytics:read|management:read` | `app.monitorings.analytics.heatmap` |
| `GET` | `/api/monitorings/{monitoring}/incidents` | `analytics:read|management:read` | `app.monitorings.analytics.incidents` |
| `GET` | `/api/monitorings/{monitoring}/notification-preferences` | `management:read` | `app.monitorings.notification-preferences.show` |
| `PATCH` | `/api/monitorings/{monitoring}/notification-preferences` | `management:write` | `app.monitorings.notification-preferences.update` |
| `POST` | `/api/monitorings/{monitoring}/ownership/private` | `management:write` | `app.monitorings.ownership.private.store` |
| `POST` | `/api/monitorings/{monitoring}/ownership/team` | `management:write` | `app.monitorings.ownership.team.store` |
| `GET` | `/api/monitorings/{monitoring}/response-times` | `analytics:read|management:read` | `app.monitorings.analytics.response-times` |
| `DELETE` | `/api/monitorings/{monitoring}/results` | `management:write` | `app.monitorings.results.destroy` |
| `GET` | `/api/monitorings/{monitoring}/server-health-telemetry` | `analytics:read|management:read` | `app.monitorings.analytics.server-health-telemetry` |
| `GET` | `/api/monitorings/{monitoring}/ssl` | `analytics:read|management:read` | `app.monitorings.analytics.ssl` |
| `GET` | `/api/monitorings/{monitoring}/status` | `analytics:read|management:read` | `app.monitorings.analytics.status` |
| `GET` | `/api/monitorings/{monitoring}/uptime-calendar` | `analytics:read|management:read` | `app.monitorings.analytics.uptime-calendar` |
| `GET` | `/api/monitorings/{monitoring}/uptime-downtime` | `analytics:read|management:read` | `app.monitorings.analytics.uptime-downtime` |
| `GET` | `/api/monitorings/{monitoring}/uptime-downtime-summary` | `analytics:read|management:read` | `app.monitorings.analytics.uptime-downtime-summary` |

#### `monitoring-groups`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/monitoring-groups` | `management:read` | `app.monitoring-groups.index` |
| `POST` | `/api/monitoring-groups` | `management:write` | `app.monitoring-groups.store` |
| `GET` | `/api/monitoring-groups/assignment-options` | `management:read` | `app.monitoring-groups.assignment-options` |
| `DELETE` | `/api/monitoring-groups/{monitoringGroup}` | `management:write` | `app.monitoring-groups.destroy` |
| `GET` | `/api/monitoring-groups/{monitoringGroup}` | `management:read` | `app.monitoring-groups.show` |
| `PATCH` | `/api/monitoring-groups/{monitoringGroup}` | `management:write` | `app.monitoring-groups.update` |
| `GET` | `/api/monitoring-groups/{monitoringGroup}/sla-report` | `management:read` | `app.monitoring-groups.sla-report` |

#### `status-pages`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/status-pages` | `management:read` | `app.status-pages.index` |
| `POST` | `/api/status-pages` | `management:write` | `app.status-pages.store` |
| `GET` | `/api/status-pages/options` | `management:read` | `app.status-pages.options` |
| `DELETE` | `/api/status-pages/{statusPage}` | `management:write` | `app.status-pages.destroy` |
| `GET` | `/api/status-pages/{statusPage}` | `management:read` | `app.status-pages.show` |
| `PATCH` | `/api/status-pages/{statusPage}` | `management:write` | `app.status-pages.update` |
| `POST` | `/api/status-pages/{statusPage}/announcements` | `management:write` | `app.status-pages.announcements.store` |
| `DELETE` | `/api/status-pages/{statusPage}/announcements/{announcement}` | `management:write` | `app.status-pages.announcements.dismiss` |
| `PATCH` | `/api/status-pages/{statusPage}/announcements/{announcement}` | `management:write` | `app.status-pages.announcements.update` |
| `GET` | `/api/status-pages/{statusPage}/incidents` | `management:read` | `app.status-pages.incidents.index` |
| `GET` | `/api/status-pages/{statusPage}/incidents/{incident}` | `management:read` | `app.status-pages.incidents.show` |
| `POST` | `/api/status-pages/{statusPage}/incidents/{incident}/follow-ups` | `management:write` | `app.status-pages.incidents.follow-ups.store` |
| `DELETE` | `/api/status-pages/{statusPage}/incidents/{incident}/follow-ups/{incidentFollowUp}` | `management:write` | `app.status-pages.incidents.follow-ups.destroy` |
| `PATCH` | `/api/status-pages/{statusPage}/incidents/{incident}/follow-ups/{incidentFollowUp}` | `management:write` | `app.status-pages.incidents.follow-ups.update` |
| `PATCH` | `/api/status-pages/{statusPage}/incidents/{incident}/metadata` | `management:write` | `app.status-pages.incidents.metadata.update` |
| `PATCH` | `/api/status-pages/{statusPage}/incidents/{incident}/review` | `management:write` | `app.status-pages.incidents.review.update` |
| `POST` | `/api/status-pages/{statusPage}/incidents/{incident}/timeline` | `management:write` | `app.status-pages.incidents.timeline.store` |
| `DELETE` | `/api/status-pages/{statusPage}/incidents/{incident}/timeline/{incidentTimelineEvent}` | `management:write` | `app.status-pages.incidents.timeline.destroy` |
| `PATCH` | `/api/status-pages/{statusPage}/incidents/{incident}/timeline/{incidentTimelineEvent}` | `management:write` | `app.status-pages.incidents.timeline.update` |
| `POST` | `/api/status-pages/{statusPage}/incidents/{incident}/updates` | `management:write` | `app.status-pages.incidents.updates.store` |
| `DELETE` | `/api/status-pages/{statusPage}/incidents/{incident}/updates/{incidentUpdate}` | `management:write` | `app.status-pages.incidents.updates.suppress` |
| `PATCH` | `/api/status-pages/{statusPage}/incidents/{incident}/updates/{incidentUpdate}` | `management:write` | `app.status-pages.incidents.updates.update` |
| `PATCH` | `/api/status-pages/{statusPage}/publication` | `management:write` | `app.status-pages.publication.update` |

#### `incidents`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/incidents/analytics` | `management:read` | `app.incidents.analytics` |
| `GET` | `/api/incidents/open/diagnoses` | `management:read` | `app.incidents.diagnoses.open` |
| `GET` | `/api/incidents/{incident}/diagnosis` | `management:read` | `app.incidents.diagnosis.show` |
| `POST` | `/api/incidents/{incident}/diagnosis` | `management:write` | `app.incidents.diagnosis.recompute` |

#### `maintenance`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/maintenance` | `management:read` | `app.maintenance.index` |
| `POST` | `/api/maintenance` | `management:write` | `app.maintenance.store` |
| `GET` | `/api/maintenance/capabilities` | `management:read` | `app.maintenance.capabilities` |
| `GET` | `/api/maintenance/one-off` | `management:read` | `app.maintenance.one-off.index` |
| `DELETE` | `/api/maintenance/one-off/{monitoring}` | `management:write` | `app.maintenance.one-off.destroy` |
| `GET` | `/api/maintenance/recurring` | `management:read` | `app.maintenance.recurring.index` |
| `PATCH` | `/api/maintenance/recurring/{maintenanceWindow}` | `management:write` | `app.maintenance.recurring.update` |

#### `teams`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/teams` | `management:read` | `app.teams.index` |
| `POST` | `/api/teams` | `management:write` | `app.teams.store` |
| `DELETE` | `/api/teams/{team}` | `management:write` | `app.teams.destroy` |
| `GET` | `/api/teams/{team}` | `management:read` | `app.teams.show` |
| `PATCH` | `/api/teams/{team}` | `management:write` | `app.teams.update` |
| `GET` | `/api/teams/{team}/invitations` | `management:read` | `app.teams.invitations.index` |
| `POST` | `/api/teams/{team}/invitations` | `management:write` | `app.teams.invitations.store` |
| `DELETE` | `/api/teams/{team}/invitations/{teamInvitation}` | `management:write` | `app.teams.invitations.destroy` |
| `DELETE` | `/api/teams/{team}/leave` | `management:write` | `app.teams.leave` |
| `GET` | `/api/teams/{team}/members` | `management:read` | `app.teams.members.index` |
| `DELETE` | `/api/teams/{team}/members/{teamMembership}` | `management:write` | `app.teams.members.destroy` |
| `PATCH` | `/api/teams/{team}/members/{teamMembership}` | `management:write` | `app.teams.members.update` |

#### `notifications`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/notifications` | `management:read` | `app.notifications.index` |
| `PATCH` | `/api/notifications/read-all` | `management:write` | `app.notifications.read-all` |
| `PATCH` | `/api/notifications/{notification}/read` | `management:write` | `app.notifications.read` |

#### `profile`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `PATCH` | `/api/profile` | `unscoped` | `app.profile.update` |
| `DELETE` | `/api/profile/account` | `unscoped` | `app.profile.destroy` |
| `GET` | `/api/profile/api-keys` | `unscoped` | `app.profile.api-keys.index` |
| `POST` | `/api/profile/api-keys` | `unscoped` | `app.profile.api-keys.store` |
| `DELETE` | `/api/profile/api-keys/{apiKey}` | `unscoped` | `app.profile.api-keys.destroy` |
| `GET` | `/api/profile/notification-settings` | `management:read` | `app.profile.notification-settings.show` |
| `PATCH` | `/api/profile/notification-settings` | `management:write` | `app.profile.notification-settings.update` |
| `POST` | `/api/profile/notification-settings/{channel}/test` | `management:write` | `app.profile.notification-settings.test` |
| `PUT` | `/api/profile/password` | `unscoped` | `app.profile.password.update` |
| `PATCH` | `/api/profile/preferences` | `unscoped` | `app.profile.preferences.update` |

#### `dashboard`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/dashboard` | `management:read` | `app.dashboard` |

#### `api-keys`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/api-keys` | `unscoped` | `api-keys.index` |
| `POST` | `/api/api-keys` | `unscoped` | `api-keys.store` |
| `DELETE` | `/api/api-keys/{apiKey}` | `unscoped` | `api-keys.destroy` |
| `GET` | `/api/api-keys/{apiKey}` | `unscoped` | `api-keys.show` |

#### `admin`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/admin/activity-logs` | `unscoped` | `app.admin.activity-logs.index` |
| `GET` | `/api/admin/api-logs` | `unscoped` | `app.admin.api-logs.index` |
| `GET` | `/api/admin/dashboard` | `unscoped` | `app.admin.dashboard` |
| `GET` | `/api/admin/packages` | `unscoped` | `app.admin.packages.index` |
| `POST` | `/api/admin/packages` | `unscoped` | `app.admin.packages.store` |
| `DELETE` | `/api/admin/packages/{package}` | `unscoped` | `app.admin.packages.destroy` |
| `PATCH` | `/api/admin/packages/{package}` | `unscoped` | `app.admin.packages.update` |
| `GET` | `/api/admin/server-instances` | `unscoped` | `app.admin.server-instances.index` |
| `POST` | `/api/admin/server-instances` | `unscoped` | `app.admin.server-instances.store` |
| `DELETE` | `/api/admin/server-instances/{serverInstance}` | `unscoped` | `app.admin.server-instances.destroy` |
| `PATCH` | `/api/admin/server-instances/{serverInstance}` | `unscoped` | `app.admin.server-instances.update` |
| `GET` | `/api/admin/users` | `unscoped` | `app.admin.users.index` |
| `POST` | `/api/admin/users` | `unscoped` | `app.admin.users.store` |
| `DELETE` | `/api/admin/users/{user}` | `unscoped` | `app.admin.users.destroy` |
| `PATCH` | `/api/admin/users/{user}` | `unscoped` | `app.admin.users.update` |
| `POST` | `/api/admin/users/{user}/verify` | `unscoped` | `app.admin.users.verify` |

#### `public`

| Method | Path | Ability | Operation |
| --- | --- | --- | --- |
| `GET` | `/api/public/monitoring-locations` | `none` | `public.monitoring-locations.index` |
| `GET` | `/api/public/monitorings/{monitoring}/badge` | `none` | `public.monitorings.badge` |
| `GET` | `/api/public/monitorings/{monitoring}/uptime-calendar` | `none` | `public.monitorings.uptime-calendar` |
| `GET` | `/api/public/status-pages/{statusPage}/uptime-calendar` | `none` | `public.status-pages.uptime-calendar` |
| `GET` | `/api/public/status/{status}` | `none` | `public.status.show` |
| `POST` | `/api/public/status/{status}/subscribers` | `none` | `public.status.subscribers.store` |
| `POST` | `/api/public/status/{status}/subscribers/confirm/{token}` | `none` | `public.status.subscribers.confirm` |
| `DELETE` | `/api/public/status/{status}/subscribers/unsubscribe/{token}` | `none` | `public.status.subscribers.destroy` |

## Keeping the contract current

`docs/api/openapi.json` is generated from the registered routes and form requests:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml exec php php artisan openapi:generate
```

`tests/Feature/Api/OpenApiSpecTest.php` fails when a route is added, removed, or changed without
regenerating the file, and when an MCP tool maps to an undocumented operation. Run
`php artisan openapi:generate --check` to verify without writing.

## Intentional exclusions

| Area | Reason |
| --- | --- |
| `/api/mobile/*` | Native app contract with its own token type, documented in [external-v1.md](external-v1.md). |
| `/api/instances/*` | Scanner instance callbacks, documented in [webguard-instance-api.md](../integrations/webguard-instance-api.md). |
| `/api/auth/*`, `/api/session*` | Browser sign-in and session flows. |
| `/api/server-health/*` | Agent telemetry ingestion, documented in [server-agent-api.md](../integrations/server-agent-api.md). |
| `/api/mcp` | MCP endpoint, documented in [mcp.md](../mcp.md). |
| Notification channel secrets | Webhook URLs and bot tokens are written through `PATCH /api/profile/notification-settings`; MCP never reads or writes them. |
