# Shared API contract

`/api/*` is the supported REST API for platform users and integrations. It
uses a Sanctum personal-access token. Browser sessions and personal-access
tokens use the same standard resource routes; mobile, scanner-instance, public,
and authentication flows have explicit sub-namespaces.

## Public monitoring locations

`GET /api/public/monitoring-locations` is an unauthenticated, read-only
allowlisting contract for public WebGuard monitoring locations. It returns a
deterministically ordered `data` array of active locations with `code`, `name`,
`country_code`, `region`, `allowlist_ips`, and `active`, plus `meta.version`
and `meta.generated_at`. Inactive locations and locations without a globally
routable source IP are omitted. The response is safe to cache for five minutes
(`Cache-Control: public, max-age=300, stale-while-revalidate=60`). A version 1
response changes only additively; rate-limit errors use Laravel's standard
`429` response.

## Compatibility policy

- Existing JSON bodies, status codes, validation errors, and authorization
  behavior are stable contract. Additive fields are allowed; removals and
  shape changes require a coordinated major-version migration.
- Standard resources resolve through the shared application route family. The
  caller identity and token abilities determine visibility and write access;
  browser and token callers do not receive separate URL contracts.
- Monitoring lists use the existing Laravel paginator with `per_page` from 1
  through 100 (default 25), ordered by `name` and then `id`. Check history uses
  the existing offset pagination with `limit` from 1 through 1,000 (default 100),
  ordered by descending check timestamp and `id`. Team, membership, invitation,
  and push-device lists retain their legacy complete `data` arrays; their order
  is deterministic (`name`/`id`, creation/`id`, or registration/`id` as
  appropriate). A new paginated envelope for those endpoints requires v2.

## Rate limits

Non-mobile external tokens have a per-user limit of five requests per 60-second
window. Responses include `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and
`X-RateLimit-Reset` (Unix timestamp); a limited request returns `429`,
`Retry-After`, and zero remaining requests.

The rate limiter deliberately does not log access tokens or authorization
headers. Mobile app tokens retain their existing separate behavior.

## Errors, retries, and lifecycle

- Existing validation errors keep Laravel's `{ "message", "errors" }`
  shape. Authorization and missing resources keep their existing HTTP status
  and body. Problem-detail envelopes are reserved for a compatible future API
  version.
- `X-Request-Id` is generated for every authenticated external response,
  including validation and rate-limit responses. Include it when contacting
  support; request tokens and authorization headers are never logged.
- `GET`, `HEAD`, `PUT`, `PATCH`, and `DELETE` follow their HTTP idempotency
  semantics. `POST /mobile-push-devices` is idempotent for a provider/token
  pair. Mobile status-page communications and mobile maintenance scheduling
  define their required `Idempotency-Key` behavior below. Other creation
  endpoints do not currently accept `Idempotency-Key`, so clients must avoid
  blind retries after an unknown write outcome.
- API changes are additive within the supported contract. A deprecated endpoint will announce a
  successor through `Deprecation`, `Sunset`, and `Link` headers before removal;
  a response-shape change requires v2.
- Least-privilege token enforcement is available behind
  `EXTERNAL_API_ENFORCE_TOKEN_ABILITIES`. It remains `false` by default for
  backwards compatibility. Once enabled, safe methods require `external:read`
  and mutating methods require `external:write`; existing wildcard tokens
  continue to work.

## Named scoped API keys

Authenticated browser sessions can create, list, inspect, and revoke keys at
`/api/api-keys`. The endpoints use the same authenticated session and CSRF
protection as the profile screen; newly created scoped bearer keys cannot manage
keys themselves. This prevents a telemetry or analytics integration from
creating another key or escalating its permissions.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/api-keys?state=active|revoked&per_page=25` | Paginated non-secret key metadata. |
| `POST` | `/api/api-keys` | Creates a named key; returns its plaintext value exactly once. |
| `GET` | `/api/api-keys/{id}` | Returns metadata for one key owned by the authenticated user. |
| `DELETE` | `/api/api-keys/{id}` | Immediately and idempotently revokes one owned key. |

Creation requires a non-empty, unique-per-user `name` and a non-empty
`abilities` array. The only new key abilities are:

- `server-health:write`: may only send telemetry to the bearer Server Health
  endpoint for a monitoring the key owner may manage;
- `analytics:read`: may only read the documented monitoring reporting routes
  below; it cannot list or mutate monitorings, teams, devices, or API keys.

Metadata includes the key ID, display name, abilities, non-secret Sanctum
prefix, creation time, last-use time, and revocation state. It never includes a
plaintext token, token hash, or authorization header. Deleted key records are
retained as revoked metadata for auditability and cannot authenticate again.

The analytics routes are `GET /api/monitorings/{monitoring}` and its
`status`, `uptime-downtime`, `uptime-downtime-summary`, `response-times`,
`checks`, `incidents`, `heatmap`, `ssl`, and `uptime-calendar` subresources.
All retain their existing ownership checks and response contracts.

## Model Context Protocol

`POST /api/mcp` exposes monitoring queries through the MCP Streamable HTTP
transport. Connect with a WebGuard API key that has the `analytics:read`
ability, using `Authorization: Bearer <token>`. The endpoint uses the existing
monitoring analytics read boundary and does not provide write tools. Legacy
personal-access tokens follow the `EXTERNAL_API_ENFORCE_TOKEN_ABILITIES`
setting and require `external:read` when enforcement is enabled. Mobile-app
tokens are not accepted.

The server exposes these tools:

| Tool | Inputs | Result |
| --- | --- | --- |
| `list_monitorings` | Optional `limit` from 1 to 100 (default 50) | Visible monitoring IDs, names, types, and statuses. Targets and credentials are omitted. |
| `get_monitoring_analytics` | Required `monitoring_id`; optional `days` from 1 to 365 (default 30) | The monitoring analytics payload exposed by the Core API, scoped to a monitoring visible to the authenticated user. |

Create a key from the profile's API key settings and select **Read analytics**.
Configure an MCP client to connect to `<APP_URL>/api/mcp` and pass the key as
its bearer token. MCP clients make several protocol requests per connection;
the endpoint applies a 60-request-per-minute rate limit per authenticated
caller.

Existing pre-scoped tokens remain compatible. They are not converted
to new keys automatically; rotate them from the profile when practical.

## Mobile monitoring management

Native clients manage monitorings through `/api/mobile/monitorings`. These
routes use mobile-token authentication and keep their mobile-specific payloads;
the shared `/api/monitorings` family is reserved for browser sessions and
standard personal-access tokens.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/mobile/monitorings?per_page=25` | Paginated monitoring configuration visible to the token owner. |
| `POST` | `/api/mobile/monitorings` | Creates one private or team-owned monitoring. |
| `PATCH` | `/api/mobile/monitorings/{monitoring}` | Updates one manageable monitoring, including its lifecycle state. |
| `DELETE` | `/api/mobile/monitorings/{monitoring}` | Soft-deletes one manageable monitoring. |
| `POST` | `/api/mobile/monitorings/{monitoring}/ownership/team` | Moves a private monitoring to a team administered by the caller. |
| `DELETE` | `/api/mobile/monitorings/{monitoring}/ownership/team` | Returns one manageable team monitoring to private ownership. |

The validated `type` values are `http`, `ping`, `keyword`, `port`,
`heartbeat`, `server_health`, `domain_expiration`, and `dns_record`.
`status` is `active` or `paused`, so a `PATCH` performs the supported
pause/activate transition. Request validation is type-aware: HTTP and keyword
settings, ports, DNS expectations, heartbeat configuration, and server-health
thresholds are accepted only for their matching monitoring type. Successful
create, update, and ownership responses return the server-confirmed monitoring
representation, including `ownership.can_manage` and personal
`group_assignments` where applicable.

Creation does not accept an idempotency key and clients must not blindly retry
an unknown `POST` outcome. `PATCH` and `DELETE` follow ordinary HTTP retry
semantics. A completed delete returns `204`; a later retry returns the stable
`404` not-found outcome. Inaccessible private monitorings also return `404`,
while a visible but non-manageable team monitoring returns `403`. Validation
failures retain Laravel's `{ "message", "errors" }` response shape for native
presentation.

## Mobile monitoring detail

`GET /api/mobile/monitorings/{monitoring}` is the authenticated native-app
detail contract. It is separate from browser UI and scanner-instance routes and
only resolves monitorings visible to the current token owner. Unauthenticated
requests return `401`; a missing or inaccessible monitoring returns `404`.

The response is an envelope with `data` and `meta`. `data` contains the
monitoring summary, current check state, availability and response-time series,
recent incidents, heatmap, maintenance context, SSL and domain results, uptime
calendar, and capability flags. The server derives all monitoring semantics;
clients must not infer uptime, maintenance, or certificate status from raw
checks.

`days` is optional, defaults to `30`, and is bounded from `1` through `90`.
Incident history uses deterministic `down_at`/`id` descending order and supports
`incident_limit` (default `20`, maximum `50`) plus `incident_offset`. Its
metadata provides `has_more` and `next_offset` for retry-safe pagination.
Invalid query values retain Laravel's stable `422` validation envelope.

`meta.sections` has a `state` and `generated_at` entry for every section. The
state is `current`, `stale`, `empty`, or `unavailable`, allowing a native client
to render partial detail safely without treating an unavailable SSL or domain
result as a failed monitoring check.

## Mobile monitoring groups and ownership

Native clients manage personal monitoring groups through `/api/mobile/monitoring-groups`.
The list and assignment-options endpoints use the standard Laravel paginator
(`per_page` 1 through 100, default 25) and order by name and ID. Group payloads
contain private ownership metadata, the number of safely assignable monitorings,
and, for a detail or write response, the assigned private monitoring summaries.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/mobile/monitoring-groups` | Paginated groups owned and manageable by the current user. |
| `GET` | `/mobile/monitoring-groups/{id}` | One group and its safely visible private assignments. |
| `GET` | `/mobile/monitoring-groups/assignment-options` | Paginated private monitorings available for assignment. |
| `POST` | `/mobile/monitoring-groups` | Creates a personal group and optional assignments. |
| `PATCH` | `/mobile/monitoring-groups/{id}` | Renames a group and/or replaces its supplied assignments. |
| `DELETE` | `/mobile/monitoring-groups/{id}` | Deletes the group; monitorings remain and their pivot assignments are removed. |

Groups are always personal. Only monitorings privately owned by the current user
can be assigned; team-owned and foreign monitorings are rejected with `422` and
field-keyed Laravel validation errors. A monitoring moved to team ownership is
detached from all personal groups. Monitoring management responses now add
`ownership` and `group_assignments` fields so mobile clients can render the
result of create, update, and ownership changes without browser-form data.

`POST` does not accept an idempotency key and should not be blindly retried after
an unknown outcome. `PATCH` replaces assignments only when `monitoring_ids` is
provided; omitting it leaves existing assignments unchanged. `DELETE` returns
`404` for an unknown or inaccessible group, so clients should re-list groups
after an unknown deletion outcome.

## Mobile status-page incident workspace

Native clients use `/api/mobile/status-pages` for a private workspace that
is deliberately separate from public status-page output and browser management
routes. A status page is visible only to its owner. Incidents are included only
when their monitoring is manageable by that user; team-owned monitorings
therefore require the existing team-administrator permission. Inaccessible
status pages and incidents return `404`, avoiding ownership disclosure.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/mobile/status-pages` | Paginated private status-page summaries, including publication state, component and verified subscriber counts, and open incident count. |
| `GET` | `/mobile/status-pages/{statusPage}` | One workspace with safely manageable components and monitoring-group context. |
| `PATCH` | `/mobile/status-pages/{statusPage}/publication` | Publishes or unpublishes the status page with `is_public`. |
| `GET` | `/mobile/status-pages/{statusPage}/incidents?state=open|resolved` | Paginated incident workspaces for the status page. |
| `GET` | `/mobile/status-pages/{statusPage}/incidents/{incident}` | One incident with private metadata, readiness, updates, follow-ups, and timeline. |
| `POST` | `/mobile/status-pages/{statusPage}/incidents/{incident}/updates` | Creates a public incident update. |
| `PATCH` | `/mobile/status-pages/{statusPage}/incidents/{incident}/metadata` | Updates incident severity, affected service, and private metadata. |
| `PATCH` | `/mobile/status-pages/{statusPage}/incidents/{incident}/review` | Updates post-incident review fields. |
| `POST`, `PATCH`, `DELETE` | `/mobile/status-pages/{statusPage}/incidents/{incident}/follow-ups[/{followUp}]` | Creates, updates, or removes follow-up work. |
| `POST`, `PATCH`, `DELETE` | `/mobile/status-pages/{statusPage}/incidents/{incident}/timeline[/{timelineEvent}]` | Creates, updates, or removes custom timeline entries. |

`POST` requests for updates, follow-ups, and custom timeline events require an
`Idempotency-Key` header (one to 100 characters). The key is stored per
incident and communication type: the first request returns `201`, while a
retry with the same key returns the original resource with `200` and creates no
additional update, follow-up, timeline event, or audit record. `PATCH` and
`DELETE` have normal HTTP idempotency semantics; clients should use the returned
current resource state or re-read after an unknown outcome rather than infer a
concurrent write result.

The workspace uses ordinary Laravel field-keyed validation errors. Mutations
are recorded in the activity log with the authenticated user as causer; token
values and authorization headers are never recorded. Public status-page
endpoints and browser management routes keep their existing contracts and do
not expose this private workspace payload.

## Mobile notification board and preferences

Native clients use `/api/mobile/notification-board` for a token-scoped
notification history. Entries include a monitoring summary, event type,
severity, occurrence time, and the authenticated user's read state. The
default response excludes read entries; `show_read=true` includes them.
Results are ordered newest first and use an opaque `next_cursor`, so clients
must pass the cursor unchanged to fetch the next page.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/mobile/notification-board?cursor=&limit=&event_type=&show_read=` | Cursor-paginated private notification history and unread count. |
| `PATCH` | `/mobile/notification-board/{notification}/read` | Marks one visible notification read; repeated calls remain successful. |
| `PATCH` | `/mobile/notification-board/read-all` | Marks the token owner's notifications read; repeated calls remain successful. |
| `GET` | `/mobile/monitorings/{monitoring}/notification-preferences` | Effective notification settings, source, permitted channels, and mutation capability. |
| `PATCH` | `/mobile/monitorings/{monitoring}/notification-preferences` | Updates the authenticated user's monitoring preferences. |

The board emits machine-readable incident, recovery, maintenance, performance,
and SSL/domain expiry events. The `delivery_failure` filter and
`delivery_status` report a failed channel delivery without changing the
underlying notification event.
Notification state is stored per recipient, so a read
operation never changes another user's history. Preference updates apply to
the caller's recipient record; private-monitoring updates also synchronize the
legacy monitoring defaults for compatibility. Missing or invisible resources
return `404`; malformed cursors and invalid fields use Laravel's normal `422`
validation response.

## Mobile maintenance operations

Native clients use the dedicated \`/api/mobile/maintenance\` family rather
than browser maintenance routes. It returns only windows visible to the token
owner; an item separately reports \`can_manage\`. Private monitorings are
manageable by their owner, while team-owned monitorings require the existing
team-administrator role. A team member can therefore see a window without
being able to change or cancel it.

| Method | Path | Result |
| --- | --- | --- |
| \`GET\` | \`/mobile/maintenance/capabilities\` | Manageable monitoring IDs, ownership metadata, personal monitoring groups, and retry capability. |
| \`GET\` | \`/mobile/maintenance/one-off?state=active|upcoming|expired\` | Paginated visible one-off windows. |
| \`GET\` | \`/mobile/maintenance/recurring?state=active|upcoming|expired|disabled\` | Paginated visible recurring windows, including enabled state and next occurrence. |
| \`POST\` | \`/mobile/maintenance\` | Schedules one-off or recurring maintenance with the existing \`mode\` and \`scope\` request fields. |
| \`PATCH\` | \`/mobile/maintenance/recurring/{maintenanceWindow}\` | Enables or disables an authorized recurring window. |
| \`DELETE\` | \`/mobile/maintenance/one-off/{monitoring}\` | Cancels one authorized one-off maintenance window. |

All timestamps are ISO-8601 with offsets. Recurring payloads include their
IANA timezone, duration, repeat-until boundary, and server-calculated next
occurrence so the client does not recalculate recurrence or DST rules.
\`state\` is one of \`active\`, \`upcoming\`, \`expired\`, or \`disabled\`; recurring
windows report \`disabled\` only when they have been switched off.

\`POST\` requires an \`Idempotency-Key\` header (one to 100 characters). A retry
with the same key and payload returns the original operation result with \`200\`
and \`idempotent: true\`; the initial operation returns \`201\`. Reusing a key for
a different payload yields a field-keyed \`422\` validation error. \`PATCH\` and
\`DELETE\` retain normal HTTP idempotency semantics. Existing Laravel validation
errors remain \`{ "message", "errors" }\`, while missing, invisible, or
unmanageable mutation targets return \`404\`.

Scribe generates the OpenAPI and reference documentation from route metadata,
request rules, controller annotations, and this configuration. Do not edit the
generated artifacts manually.
