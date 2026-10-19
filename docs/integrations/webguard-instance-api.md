# WebGuard Instance API contract

**Status:** Current instance contract.  
**Core tracking:** [WebGuard Core #593](https://github.com/marcel-breuer/webguard/issues/593)  
**Instance tracking:** [webguard-instance #35](https://github.com/marcel-breuer/webguard-instance/issues/35)

The derived-health migration is additionally tracked by [WebGuard Core #632](https://github.com/marcel-breuer/webguard/issues/632) and [webguard-instance #42](https://github.com/marcel-breuer/webguard-instance/issues/42).

This document is the implementation contract for the separate
[`webguard-instance`](https://github.com/marcel-breuer/webguard-instance)
repository. It is the only Core document that describes scanner-instance API
changes. Browser UI routes are not part of this contract.

The website-check cadence is tracked by [WebGuard Core #716](https://github.com/marcel-breuer/webguard/issues/716) and [webguard-instance #46](https://github.com/marcel-breuer/webguard-instance/issues/46).

## Compatibility and base URL

Core exposes one instance base URL. The instance adapter must keep the Core base
URL configurable:

```text
https://core.example/api/instances
```

For local Docker setup, see [installation](../installation.md#webguard-instance-integration-with-local-docker).

## Authentication and transport

Every scanner request sends these headers:

| Header | Required | Description |
| --- | --- | --- |
| `X-INSTANCE-CODE` | yes | Active `server_instances.code` identifying the scanner location. |
| `X-API-KEY` | yes | Instance secret verified by Core; never log, persist in output, or include in retry diagnostics. |
| `Accept: application/json` | recommended | Requests JSON responses. |
| `Content-Type: application/json` | required for writes | Encodes callback payloads. |
| `Idempotency-Key` | recommended for result callbacks | UUID v4 identifying one monitoring execution; reuse it for every retry of that execution. |

Core rejects missing, inactive, unknown, or invalid credentials with `401` and
`{"message":"Unauthorized"}`. A scanner may access only monitorings assigned to
its authenticated instance code. It must use HTTPS outside trusted local Docker
networks.

Core stores instance keys as one-way hashes. An administrator can rotate a key
through server-instance management; the previous key stops working as soon as
the update succeeds, and the key is never included in the response. Instance
routes do not currently have an additional per-instance request quota. Any
future quota must be coordinated with the instance client because `429` and
`Retry-After` behavior affects callback retry handling.

## Endpoints

Prefix every path with `https://core.example/api/instances`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/monitorings` | Fetch active monitorings assigned to the instance. |
| `POST` | `/monitoring-responses` | Submit a monitoring result. |
| `POST` | `/incidents` | Open an incident for a single-location monitoring. |
| `PUT` | `/incidents/{monitoring}` | Close an open incident. |
| `POST` | `/ssl-results` | Submit SSL result data. |
| `POST` | `/domain-results` | Submit domain-expiration result data. |

## Monitoring retrieval

`GET /monitorings` requires the following query parameter:

| Parameter | Required | Rules |
| --- | --- | --- |
| `location` | yes | Active instance code; must equal `X-INSTANCE-CODE`. |
| `type` | no | One supported monitoring type. Without it, Core excludes heartbeat and server-health monitorings. |

The successful response is a JSON array. Each monitoring item contains its stable
`id`, `name`, `type`, `target`, network and HTTP settings, expected statuses,
maintenance state, preferred locations, heartbeat settings, and type-specific
domain or server-health fields. The exact current projection is implemented by
`App\\Http\\Resources\\Instance\\MonitoringResource`.

Every active monitoring item additionally includes the additive
`check_interval_seconds` field. It is the minimum start-to-start interval for a
regular check at the requesting location. HTTP and keyword monitorings currently
receive `900`; other active polling types retain `300` until they receive a
dedicated cadence policy. Instances must not run a regular check earlier than
this value. Queue pressure, maintenance, and bounded jitter may delay a check.
Heartbeat and server-health monitorings continue to use their dedicated report
fields and are not redefined by this value. Older instances may ignore the
unknown field during the rollout.

The projection can include `auth_username`, `auth_password`, `http_headers`, and
`http_body` when a monitoring needs them. Treat these as secrets: hold them only
in memory for execution, redact them from logs, and never relay them to another
service.

An instance requesting another location receives `403` with
`{"message":"Unauthorized location"}`.

## Callback payloads

All callbacks require a `monitoring_id` assigned to the authenticated instance.
An unassigned monitoring returns `403` with
`{"message":"Unauthorized monitoring"}`.

| Endpoint | Required fields | Optional fields | Success response |
| --- | --- | --- | --- |
| `POST /monitoring-responses` | `monitoring_id` plus legacy `status` or sufficient raw evidence | `http_status_code` (100-599), `response_time` (>= 0), `vital_values`, `check_interval_seconds` (60-65535) | `{"message":"Monitoring response stored successfully."}` |
| `POST /incidents` | `monitoring_id`, `down_at` | — | `{"message":"Incident stored successfully."}` |
| `PUT /incidents/{monitoring}` | `up_at` | — | `{"message":"Incident updated successfully."}` |
| `POST /ssl-results` | `monitoring_id`, `is_valid` | `expires_at`, `issuer`, `issued_at` | `{"message":"SSL result stored successfully."}` |
| `POST /domain-results` | `monitoring_id`, `is_valid` | `expires_at`, `registrar`, `checked_at` | `{"message":"Domain expiration result stored successfully."}` |

`status` must be a valid Core monitoring-status enum when supplied. It is a
legacy compatibility fallback; new scanner versions should omit it and send raw
evidence instead. `vital_values.transport_succeeded` records HTTP/keyword
transport outcomes, `connection_succeeded` records ping/port outcomes,
`observed_values` records DNS values, and heartbeat callbacks use
`heartbeat_received` or `heartbeat_overdue`. Core derives availability from the
monitoring configuration and this evidence. See [derived monitoring health](../architecture/derived-monitoring-health.md) for the full contract and rollout order.

Domain-result callbacks are valid only for domain-expiration monitorings.
Validation failures use Laravel's `422` JSON validation response.

Compatible instances should return the interval they actually used in
`check_interval_seconds`. Core persists it with the result so historical
heatmaps retain the five-minute legacy cadence for old workers and the
15-minute cadence for updated website checks. Callbacks from older instances
without the field are stored with the documented 300-second legacy fallback.

For monitorings with more than one preferred location, Core manages incident
state through regional consensus. Incident open/close callbacks return `200`
with `{"message":"Incident state is managed by regional consensus."}` and the
instance must not retry them as failed writes. Closing a non-existent open
incident returns `404` with `{"message":"No open incident found."}`.

## Retry and idempotency

New instance versions send a UUID v4 `Idempotency-Key` header on
`/monitoring-responses`, `/ssl-results`, and `/domain-results`. The value is
created once per claimed or independently executed monitoring job and is reused
for every retry of that execution. It must not contain a target, credential, or
other monitoring data.

Core scopes a key to the authenticated instance, callback endpoint, and key. It
retains the resulting success response for 24 hours and prunes expired records
daily. A matching retry returns the original response without repeating the
database write, incident evaluation, notification, or observation side effects.
Reusing the same key with a different payload returns `409`. The same key may
be used for the response and domain-result callbacks of one execution because
the endpoint is part of the scope.

Instances must retry only transport failures or `5xx` responses with bounded
backoff. They must not retry `401`, `403`, `409`, `422`, or successful responses.
A timeout after a request was sent remains safe to retry with the same key.
Instances that omit the header remain compatible during rollout, but Core cannot
deduplicate those legacy callbacks.

## Coordinated changes

1. Create and link a `webguard-instance` issue before changing this contract.
2. Update Core and instance contract tests together.
3. Deploy compatible Core and instance changes as one coordinated rollout.

No Core change may require a scanner implementation change without updating this
document and creating or linking the corresponding `webguard-instance` issue.
