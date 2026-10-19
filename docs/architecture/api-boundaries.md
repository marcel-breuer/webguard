# API boundaries

**Status:** Accepted for phased implementation.  
**Tracking:** [WebGuard Core #593](https://github.com/marcel-breuer/webguard/issues/593)

## Context

WebGuard Core serves four consumers with different trust boundaries:

- external users and integrations;
- the authenticated management UI;
- scanner instances from the separate `webguard-instance` repository; and
- anonymous public status, badge, and uptime-calendar consumers.

The external API is already versioned under `/api`. Scanner routes currently
use `/api/instances/*`. The management UI mixes server-rendered HTML fragments
with JSON endpoints under `/api/*`. Those consumers must not share an implicit
HTTP contract merely because their data comes from the same application services.

## Decision

The route family, authentication boundary, and owner are as follows.

| Consumer | Route family | Authentication | Compatibility policy |
| --- | --- | --- | --- |
| External integrations | `/api/*` | Sanctum personal-access token | Stable public contract; changes are additive or use a new major version. |
| Native mobile clients | `/api/mobile/*` | Sanctum mobile-app token | Stable client contract; browser form payloads are never part of this boundary. |
| Management UI | `/api/*` | Authenticated, verified browser session; CSRF protection for unsafe requests | Private application contract; evolve with the Core UI behind reversible rollout switches. |
| Scanner instances | `/api/instances/*` | `X-INSTANCE-CODE` and `X-API-KEY` through `auth.instance` | Separate compatibility contract shared with `webguard-instance`. |
| Public read endpoints | `/api/public/*` and explicit public routes | No account authentication; strict allowlisted payloads | Never expose private monitoring, team, token, or notification data. |

The management UI and scanner instances are both internal consumers, but they do
not share endpoints, middleware, controllers, serializers, cache keys, or
authorization assumptions. Browser and standard-token routes use `/api/*`;
scanner routes use `/api/instances/*`.

Controllers are transport adapters. They authorize a request and invoke a
client-neutral application query or command. Domain services and typed data
objects may be shared; HTTP resources and presentation values may not be shared
by default. UI resources contain raw locale-neutral values, never rendered HTML,
translated relative timestamps, or named-route URLs. The explicit public
translation catalog is the exception and is limited to the approved
SvelteKit language files.

Monitoring reads follow the same rule: actor-scoped query classes own overview,
detail, and batch-card data access. Focused services own health derivation,
history, incidents, and payload assembly. Browser-specific labels, relative
timestamps, and route URLs remain in Blade presenters, while external API
resources keep their existing v1 contract until the external API workstream
introduces explicit adapters.

The first internal UI projection is `GET /api/dashboard`. It
returns raw operations data and pagination metadata; the Blade dashboard remains
the progressive-enhancement shell until the frontend migration is complete.

The dashboard shell hydrates this projection with a same-origin JSON client. It
does not parse server-rendered dashboard fragments, keeping the internal API
contract independent from Blade markup. The former asynchronous HTML endpoint
has been retired after dashboard parity was established.

The current UI read contract also exposes `GET /api/monitorings`,
`GET /api/monitorings/{monitoring}`, and the bounded
`GET /api/monitorings/cards?ids[]=` projection. These routes use
the session-and-verification boundary, return raw locale-neutral values, and
scope every result to the authenticated user. The dashboard and monitoring-card
clients use this route family; remaining page migrations stay incremental.

### First-party session contract

SvelteKit uses the shared `/api/*` namespace. It never consumes the mobile or
scanner-instance APIs for browser-session state. All
responses use a top-level `data` envelope; validation failures retain Laravel's
standard `422` JSON error shape, and authorization failures use the existing
`401` and `403` responses.

| Endpoint | Session requirement | Contract |
| --- | --- | --- |
| `GET /api/session` | Authenticated | Current user, verification state, role, locale, appearance preference, visible team context, and the Sanctum CSRF bootstrap URL. This endpoint remains available to unverified users so the client can route them to verification. |
| `POST /api/session/logout` | Authenticated | Invalidates the browser session and returns `data.authenticated: false`; it does not redirect. |
| `PATCH /api/appearance` | Authenticated member or admin | Persists a validated `light`, `dark`, or `system` preference and returns the persisted user ID and theme in the same response. Demo users remain read-only. |
| `GET /sanctum/csrf-cookie` | Browser session | Laravel Sanctum CSRF bootstrap. SvelteKit calls it before its first unsafe same-origin request and sends Laravel's standard CSRF header/cookie pair. |
| `GET /api/translations?locale=en|de` | Public read | Allowlisted SvelteKit translations from Laravel language files; unsupported locales fall back to English. |

Verified workspace routes remain behind `verified`; the session and appearance
contracts deliberately do not, matching the existing profile behavior. Every
future SvelteKit feature-area endpoint must use this namespace, a `data`
envelope, request validation, and an ownership or policy check before accepting
client-supplied identifiers. The complete browser endpoint catalogue and
transport rules are maintained in the
[first-party UI API contract](first-party-ui-contract.md).

## Current-to-target migration

Scanner instances use the single `/api/instances/*` contract. Changes to that
contract require a linked `webguard-instance` issue and matching contract tests
in both repositories.

The scanner implementation work is tracked in
[webguard-instance #35](https://github.com/marcel-breuer/webguard-instance/issues/35).
Every later change to the instance contract must create and link a specific
issue in that repository before Core implementation begins.

## HTTP conventions

- External API pagination, error envelopes, rate-limit headers, and deprecation
  policy are owned by the external API workstream. Existing `/api` response
  shapes are not silently changed.
- Internal UI and instance routes must use explicit request validation,
  authorization, and bounded response sizes.
- Cached data must include the consumer and visibility boundary in its cache key.
  Scanner data must never be returned from a browser-session cache entry, or the
  reverse.
- The operations overview uses a 30-second Redis tagged cache scoped to the
  authenticated user and service page. Monitoring responses and incidents flush
  the shared overview tag; monitoring and team-membership changes do the same
  for ownership, maintenance, and role updates. Unsupported or unavailable cache
  stores serve fresh data instead.
- Safe read responses may use private cache-control or conditional requests only
  when cache invalidation is defined. Instance write callbacks are not cached.
- `GET /api/dashboard` uses a private ETag derived from the
  user-scoped projection and returns `304 Not Modified` when it is unchanged.
- Internal UI responses expose server-generated request IDs, query counts,
  response bytes, and application timing without query bindings or request data.
  Dashboard budgets are 30 queries and 128 KiB per uncached response; cached
  responses target zero database queries.
- API latency, slow-query fingerprints, cache hit rate, queue lag and backlog,
  and scheduled-task duration are recorded as internal operational metrics.
  They do not alter browser, instance, external API, or public response payloads;
  collection and retention are documented in
  [operational metrics](operational-metrics.md).
- Authenticated standard-token responses include a server-generated `X-Request-Id`,
  including rate-limit responses, without changing their JSON bodies. New API
  work follows the documented problem-response format; existing contracts retain
  their current response shapes until a compatible migration is delivered.

## Consequences

This adds small route/controller namespaces and compatibility adapters, but
avoids a big-bang rewrite. It makes UI loading work independent from external
API stability and gives the instance repository one authoritative contract to
implement against.
