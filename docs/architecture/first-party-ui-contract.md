# First-party SvelteKit UI API contract

**Status:** Active private browser contract.  
**Tracking:** [WebGuard #736](https://github.com/marcel-breuer/webguard/issues/736)  
**Route namespace:** `/api/*`

This catalogue is the contract between the SvelteKit application and Laravel.
It is intentionally separate from the external v1, mobile, scanner-instance,
webhook, status subscription, and badge contracts. SvelteKit uses it to load
and mutate browser state; it never accesses the database directly.

## Transport rules

- Browser requests stay same-origin and use the Laravel session. Unsafe requests
  first call `GET /sanctum/csrf-cookie`, then send Laravel's XSRF header.
- Successful responses use a top-level `data` envelope. Collection endpoints
  include their documented pagination or filter metadata inside that envelope.
- Validation failures use Laravel's `422` JSON `message` and `errors` shape.
  Authentication and authorization failures remain `401` and `403`; missing or
  inaccessible resources return `404`.
- Server-owned state returned by a successful mutation is authoritative. The UI
  refreshes its route data after the one mutation and does not retry the same
  modal action.
- `X-Request-Id`, `X-Query-Count`, `X-Response-Bytes`, and `Server-Timing`
  provide private operational telemetry without exposing query bindings or
  request payloads.
- Endpoint payloads are locale-neutral data by default. The explicit
  `GET /api/translations` catalog is the exception: Laravel owns the approved
  SvelteKit language files, while SvelteKit owns rendering and interpolation.

## Session and guest authentication

`/api/auth/*` is the only guest-accessible branch. It accepts
only callers that are not authenticated, except password confirmation and
verification-mail resend, which require an authenticated session.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/auth/options` | Registration, demo, and password-reset capabilities |
| `POST` | `/auth/login` | Create a Laravel browser session |
| `POST` | `/auth/register` | Register with the existing terms, privacy, and CAPTCHA rules |
| `GET` | `/auth/demo-credentials` | Retrieve enabled demo credentials |
| `POST` | `/auth/forgot-password` | Send the existing password-reset email |
| `POST` | `/auth/reset-password` | Complete a password reset |
| `POST` | `/auth/email/verification-notification` | Resend verification mail; rate limited |
| `POST` | `/auth/confirm-password` | Establish password-confirmation state |

Inbound signed verification, invitation, password-reset, OAuth callback, and
other token URLs remain Laravel web routes. They preserve their existing URL
and signature semantics before returning users to a SvelteKit page.

## Translation catalog

The SvelteKit browser loads its language catalog from Laravel instead of keeping
a second translation table in TypeScript. The endpoint is public because it is
also used by anonymous public status pages, but it only reads the explicitly
scoped `resources/lang/{locale}/sveltekit.php` files; callers cannot select a
file path or arbitrary Laravel translation key.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/translations?locale=en|de` | Return the requested locale, the English fallback locale, and the approved flat message catalog in `data` |

The catalog files are grouped by UI domain (`common`, `navigation`,
`monitorings`, `incidents`, `status_pages`, `admin`, `profile`, `teams`,
`maintenance`, and `dynamic`). Dynamic messages use named placeholders such as
`:value` and `:count`; SvelteKit performs interpolation after receiving the
catalog. Unsupported locales fall back to English, and an unavailable catalog
falls back to the original English source text in the browser.

## Account bootstrap and settings

These endpoints require an authenticated session. The session, logout, and
appearance endpoints remain available to unverified users so the shell can
recover safely; profile and security mutations additionally require a member or
administrator role.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/session` | User, verification, role, locale, appearance, visible teams, and CSRF endpoint |
| `POST` | `/session/logout` | End the session with `data.authenticated: false` |
| `PATCH` | `/appearance` | Persist `light`, `dark`, or `system` |
| `PATCH` | `/locale` | Persist `en` or `de` and refresh the language cookie |
| `PATCH` | `/profile` | Update profile identity |
| `PUT` | `/profile/password` | Update password through Laravel validation |
| `GET`, `POST`, `DELETE` | `/profile/api-keys[/{apiKey}]` | List, create once, and revoke personal API keys |
| `DELETE` | `/profile/account` | Schedule account deletion after password confirmation |
| `GET`, `PATCH` | `/profile/notification-settings` | Read and persist notification-channel settings |
| `POST` | `/profile/notification-settings/{channel}/test` | Test a configured channel |

## Verified member workspace

Every endpoint below requires both an authenticated and verified session. Laravel
continues to enforce ownership, team membership, policies, and role checks for
every client-provided identifier.

| Area | Methods and paths | Purpose |
| --- | --- | --- |
| Dashboard | `GET /dashboard` | User-scoped overview, filters, pagination, and conditional response metadata |
| Monitorings | `GET`, `POST /monitorings`; `GET`, `PATCH`, `DELETE /monitorings/{monitoring}` | Index and create, then read, update, and delete a visible monitoring |
| Monitoring form and details | `GET /monitorings/form-options`; `GET /monitorings/{monitoring}/form-options`; `GET /monitorings/{monitoring}/detail-data`; `GET /monitorings/cards` | Typed form options, batch cards, history, health, charts, calendar, incidents, and type-specific detail data |
| Monitoring operations | `GET`, `PATCH /monitorings/{monitoring}/notification-preferences`; `POST /monitorings/{monitoring}/ownership/{team|private}` | Per-user preferences and policy-protected ownership moves |
| Monitoring groups | `GET`, `POST /monitoring-groups`; `GET /monitoring-groups/assignment-options`; `GET`, `PATCH`, `DELETE /monitoring-groups/{monitoringGroup}` | Group workspace and permitted monitoring assignments |
| Maintenance | `GET /maintenance/capabilities`; `GET /maintenance/one-off`; `GET /maintenance/recurring`; `POST /maintenance`; `PATCH /maintenance/recurring/{maintenanceWindow}`; `DELETE /maintenance/one-off/{monitoring}` | One-off and recurring maintenance workflows |
| Incident analytics | `GET /incidents/analytics` | Filtered incident trends and operational analytics |
| Notifications | `GET /notifications`; `PATCH /notifications/read-all`; `PATCH /notifications/{notification}/read` | Paginated inbox and idempotent read state |

Monitoring request payloads use the existing Form Requests. The returned
monitoring mutation result contains server-owned first-result/cycle information,
so the frontend does not hard-code the monitoring cron interval.

## Teams and status-page operations

| Area | Methods and paths | Purpose |
| --- | --- | --- |
| Teams | `GET`, `POST /teams`; `GET`, `PATCH`, `DELETE /teams/{team}`; `PATCH`, `DELETE /teams/{team}/members/{teamMembership}`; `POST`, `DELETE /teams/{team}/invitations[/{teamInvitation}]`; `DELETE /teams/{team}/leave` | Collaboration, role management, signed invitations, leave flow, and administrator-only deletion with an exact-name confirmation. Deleting a team also removes its memberships, invitations, monitorings, and dependent monitoring data; monitoring groups and status pages remain but lose those monitoring associations. |
| Status pages | `GET`, `POST /status-pages`; `GET /status-pages/options`; `GET`, `PATCH`, `DELETE /status-pages/{statusPage}`; `PATCH /status-pages/{statusPage}/publication`; `POST /status-pages/{statusPage}/announcements`; `PATCH`, `DELETE /status-pages/{statusPage}/announcements/{announcement}` | Status-page configuration, components, publication, operator announcements, and allowed monitoring or monitoring-group options |
| Status-page incidents | `GET /status-pages/{statusPage}/incidents`; `GET /status-pages/{statusPage}/incidents/{incident}`; `POST /updates`; `PATCH /metadata`, `/review`; `POST`, `PATCH`, `DELETE /follow-ups[/{incidentFollowUp}]`; `POST`, `PATCH`, `DELETE /timeline[/{incidentTimelineEvent}]` | Policy-protected incident updates, metadata, review, follow-up, and timeline actions |

The status-page incident suffixes in the last row are relative to
`/status-pages/{statusPage}/incidents/{incident}`. They return data records and
validation errors, not Blade fragments.

## Administration

The administration branch requires authenticated, verified administrators. It
is never reachable solely because the browser renders an Administration link.

| Methods and paths | Purpose |
| --- | --- |
| `GET /admin/dashboard` | Administrative counts and operational summary |
| `GET`, `POST /admin/users`; `PATCH`, `DELETE /admin/users/{user}`; `POST /admin/users/{user}/verify` | User administration and verified-state management |
| `GET`, `POST /admin/packages`; `PATCH`, `DELETE /admin/packages/{package}` | Package administration |
| `GET`, `POST /admin/server-instances`; `PATCH`, `DELETE /admin/server-instances/{serverInstance}` | Scanner-instance administration |
| `GET /admin/api-logs`; `GET /admin/activity-logs` | Auditable API-use and activity projections |

## Public compatibility boundary

Public status, subscription, and badge routes deliberately do **not** join the
authenticated UI namespace.

| Owner | Routes | Compatibility requirement |
| --- | --- | --- |
| Laravel public API | `GET /api/public/status/{status}`; subscriber `POST`, confirmation `POST`, and unsubscribe `DELETE` routes | Allowlisted public payload, resource visibility, throttling, canonical status identifier, and no private monitoring or team data |
| SvelteKit SSR | `/status/{id}` plus canonical confirmation and unsubscribe pages | Server-rendered page and one-request subscription journeys without URL changes |
| Laravel web and asset routes | `/status`, legacy status/label redirects, signed and token links, `/badge.js` | Preserve framework health, legacy redirects, confirmation compatibility, badge MIME type, cache, and cross-origin embed headers |

The route and controller boundary is protected by
`FirstPartyUiRouteBoundaryTest`, while endpoint-specific authorization,
validation, pagination, and data-isolation coverage remains in the focused
`InternalUi*ApiTest` suite.
