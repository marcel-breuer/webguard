# MCP server

WebGuard includes a [Model Context Protocol](https://modelcontextprotocol.io) server so AI assistants
and agents can monitor and operate WebGuard on a user's behalf. It is a thin layer over the
[REST API](api/rest-api.md): every tool executes a documented REST route with the caller's own token,
so validation, ownership checks, role checks, package limits, and rate limits are exactly those of the
UI and the REST API. The MCP layer adds no business logic and no privilege of its own.

- Endpoint: `POST <APP_URL>/api/mcp` (MCP Streamable HTTP transport).
- Authentication: `Authorization: Bearer <WebGuard API key>`.
- Protocol revision: 2025-11-25 (the SDK negotiates older revisions).

## Permission model

Create the key in **Profile > API keys** and grant the smallest set of abilities that the integration
needs. The server lists only the tools, resources, and prompts the key may use.

| Ability | MCP access |
| --- | --- |
| `analytics:read` | `list_monitorings`, `get_monitoring_analytics`, `list_open_incidents`, `diagnose_incident`. |
| `management:read` | Everything above plus the read tools, the `webguard://` resources, and prompts. |
| `management:write` | The write tools. Independent of read access: grant both for an operator-style agent. |

- **Writes are explicit.** Every write tool description starts with `WRITE:` (or `WRITE, DESTRUCTIVE:`),
  carries `readOnlyHint: false`, and requires `management:write`. Destructive tools set
  `destructiveHint: true`; clients should ask the user for confirmation before calling them.
- **A key acts as its owner.** A tool call can never reach data the owner could not reach in the UI.
  Calls the REST API would refuse (other users' monitorings, missing team admin role, demo accounts,
  package limits) fail with the same status and a short message.
- **No account takeover surface.** API keys, passwords, profile, admin, and mobile routes are not
  exposed through MCP, and scoped keys cannot call them over REST either.
- **No secrets in responses.** Webhook URLs, bot tokens, heartbeat tokens, passwords, and similar
  values are replaced with `[redacted]`; notification channels report only `enabled` and `configured`.
  Configure channel credentials in the WebGuard UI or through the REST API, never through an assistant.
- **Rate limits.** The MCP endpoint allows 60 protocol requests per minute per caller, and each tool
  call additionally counts against the REST budget (`EXTERNAL_API_RATE_LIMIT_PER_MINUTE`, default 60).
  A limited call returns an error with HTTP 429 and should be retried later.
- **Logging.** Tokens and authorization headers are never logged. Tool arguments are not logged.
- **Legacy personal access tokens** work as before. Without `EXTERNAL_API_ENFORCE_TOKEN_ABILITIES`
  they have full read access; write tools always require the `external:write` ability (wildcard
  tokens have it). Mobile app tokens are rejected.

Treat a `management:write` key like a password: it can delete monitorings and publish to status pages.
Revoke it from the profile when the integration is retired.

## Client setup

Replace `https://webguard.example.com` with your installation and `wg_...` with your key. Keep keys out
of version control; the examples read it from an environment variable where the client supports that.

### Claude Code

```bash
claude mcp add --transport http webguard https://webguard.example.com/api/mcp \
  --header "Authorization: Bearer $WEBGUARD_API_KEY"
```

### Claude Desktop

Claude Desktop starts local servers, so bridge the remote endpoint with `mcp-remote` in
`claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "webguard": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://webguard.example.com/api/mcp",
               "--header", "Authorization:${WEBGUARD_AUTH}"],
      "env": { "WEBGUARD_AUTH": "Bearer wg_..." }
    }
  }
}
```

### Cursor

`~/.cursor/mcp.json` (or the project's `.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "webguard": {
      "url": "https://webguard.example.com/api/mcp",
      "headers": { "Authorization": "Bearer ${env:WEBGUARD_API_KEY}" }
    }
  }
}
```

### VS Code

`.vscode/mcp.json`:

```json
{
  "inputs": [{ "id": "webguard-key", "type": "promptString", "description": "WebGuard API key", "password": true }],
  "servers": {
    "webguard": {
      "type": "http",
      "url": "https://webguard.example.com/api/mcp",
      "headers": { "Authorization": "Bearer ${input:webguard-key}" }
    }
  }
}
```

### Other clients and manual checks

Any client that supports Streamable HTTP with a custom header works. To inspect the server:

```bash
npx @modelcontextprotocol/inspector --transport http \
  --header "Authorization: Bearer $WEBGUARD_API_KEY" https://webguard.example.com/api/mcp
```

## Tool catalogue

Inputs are JSON objects described by each tool's `inputSchema`; unknown fields are rejected unless
the tool takes a full resource definition. Results are JSON objects (`structuredContent`), usually the
REST response body. Errors are tool errors (`isError: true`) with a short message such as
`The request was rejected by validation. (HTTP 422) {"name":["The name field is required."]}`.

### Analytics tools (`analytics:read`)

| Tool | Inputs | Description |
| --- | --- | --- |
| `list_monitorings` | `limit`? | Visible monitoring IDs, names, types, and statuses. Targets and credentials are omitted. |
| `get_monitoring_analytics` | `monitoring_id`, `days`? | Analytics payload (uptime, response times, incidents) for 1 to 365 days. |
| `list_open_incidents` | `limit`? | Open incidents with ranked probable-cause diagnoses. |
| `diagnose_incident` | `incident_id`, `recompute`? | Root-cause diagnosis for one incident. `recompute` refreshes the stored diagnosis. |

### Read tools (`management:read`)

| Tool | Inputs (`?` optional) | Description |
| --- | --- | --- |
| `get_dashboard` | none | Summarise the monitoring dashboard of the authenticated user: counts per status and recent activity. |
| `get_monitoring` | `monitoring_id` | Return the configuration and current state of one monitoring the user can see. Secrets such as heartbeat tokens are redacted. |
| `search_monitorings` | `search` | Search monitorings by name or target with pagination. The search term must have at least two characters. |
| `get_monitoring_notification_preferences` | `monitoring_id` | Return which of the user's notification channels fire for one monitoring. |
| `list_monitoring_groups` | none | List the user's monitoring groups. |
| `get_monitoring_group` | `group_id` | Return one monitoring group with its monitorings. |
| `list_status_pages` | `page`?, `per_page`? | List the status pages the user can manage, with publication state. |
| `get_status_page` | `status_page_id` | Return one status page with its components and publication settings. |
| `list_status_page_incidents` | `status_page_id`, `page`?, `per_page`? | List incidents that affect a status page, newest first. |
| `get_status_page_incident` | `status_page_id`, `incident_id` | Return one incident of a status page with updates, review, follow-ups and timeline. |
| `list_one_off_maintenance` | `page`?, `per_page`? | List scheduled and running one-off maintenance windows. |
| `list_recurring_maintenance` | `page`?, `per_page`? | List recurring maintenance windows. |
| `list_teams` | none | List the teams the user belongs to with their role. |
| `get_team` | `team_id` | Return one team with members and pending invitations. |
| `get_notification_settings` | none | Return which notification channels are enabled and the digest settings. Webhook URLs and bot credentials are never returned; configure them in the WebGuard UI. |
| `list_notifications` | `page`?, `per_page`? | List the in-app notification inbox of the user. |
| `get_incident_analytics` | `days`? | Aggregate incident statistics (by type, severity, recurrence, recovery time). Optional filters are passed through to the API. |

### Write tools (`management:write`)

All of these change data. Destructive tools cannot be undone.

| Tool | Inputs (`?` optional) | Description |
| --- | --- | --- |
| `create_monitoring` | `name`, `type`, `target`?, `status`, `preferred_location`, `preferred_locations`, `team_id`? | Create a monitoring. Required: name, type (http, ping, keyword, port, heartbeat, server_health, domain_expiration, dns_record), status (active or paused), preferred_location and preferred_locations (location codes), plus the fields of the chosen type such as target. Package limits and validation apply. |
| `update_monitoring` | `monitoring_id`, `name`, `type`, `status`, `preferred_location`, `preferred_locations` | Replace the configuration of a monitoring. The API requires the complete definition (name, type, status, locations and the fields of its type), so call get_monitoring first and send the full object with your changes. Set status to paused or active to pause or resume checks. |
| `delete_monitoring` | `monitoring_id` | Delete a monitoring (soft delete). Checks stop immediately. **Destructive.** |
| `reset_monitoring_results` | `monitoring_id` | Permanently delete the stored check results and incidents of a monitoring. This cannot be undone. **Destructive.** |
| `update_monitoring_notification_preferences` | `monitoring_id`, `notification_on_failure`?, `notification_channels`? | Choose which of the user's notification channels fire for one monitoring. |
| `create_monitoring_group` | `name`, `description`?, `sla_report_enabled`?, `sla_target_percentage`?, `monitoring_ids`? | Create a monitoring group. |
| `update_monitoring_group` | `group_id`, `name`?, `description`?, `sla_report_enabled`?, `sla_target_percentage`?, `monitoring_ids`? | Update a monitoring group. |
| `delete_monitoring_group` | `group_id` | Delete a monitoring group. The monitorings in it are kept. **Destructive.** |
| `create_status_page` | `name`, `description`?, `is_public`?, `auto_publish_incident_updates`?, `components` | Create a status page. Each component is a named set of monitorings (source_type manual with monitoring_ids) or a monitoring group (source_type monitoring_group with monitoring_group_id). |
| `update_status_page` | `status_page_id`, `name`, `description`?, `is_public`?, `auto_publish_incident_updates`?, `components` | Replace the settings and components of a status page. Send the complete definition. |
| `delete_status_page` | `status_page_id` | Delete a status page and its public URL. **Destructive.** |
| `set_status_page_publication` | `status_page_id`, `is_public` | Make a status page public or private. Publishing makes its content visible to anyone with the link. |
| `create_status_page_announcement` | `status_page_id`, `title`, `message`, `notify_subscribers` | Publish an announcement on a status page. With notify_subscribers true, verified subscribers receive an email. |
| `dismiss_status_page_announcement` | `status_page_id`, `announcement_id` | Remove an announcement from a status page. **Destructive.** |
| `post_incident_update` | `status_page_id`, `incident_id`, `status`, `message`, `idempotency_key`? | Publish a status update on an incident of a status page. Verified subscribers may be emailed. Never include internal notes or credentials. |
| `schedule_maintenance` | `mode`, `scope`, `monitoring_id`?, `monitoring_group_id`?, `maintenance_from`?, `maintenance_until`?, `recurring_starts_at`?, `recurring_duration_minutes`?, `recurrence`?, `recurring_repeat_until`?, `recurring_timezone`?, `idempotency_key`? | Schedule a one-off or recurring maintenance window for a monitoring or a monitoring group. Alerts are suppressed while it runs. |
| `cancel_one_off_maintenance` | `monitoring_id` | Cancel the scheduled one-off maintenance of a monitoring. **Destructive.** |
| `create_team` | `name`, `description`? | Create a team. The caller becomes its admin. |
| `invite_team_member` | `team_id`, `email`, `role` | Invite a person to a team by email. Sends an invitation email. |
| `send_notification_channel_test` | `channel` | Send a test message through one of the user's configured notification channels (email, slack, discord, teams, telegram, webhook, mobile_push). This contacts the external provider. |
| `mark_notification_read` | `notification_id` | Mark one in-app notification as read. |
| `mark_all_notifications_read` | none | Mark every in-app notification as read. |

Tools that send mail or contact an external provider (`post_incident_update`,
`create_status_page_announcement`, `invite_team_member`, `send_notification_channel_test`) set
`openWorldHint: true`. `schedule_maintenance` and `post_incident_update` accept an optional
`idempotency_key`; reuse it when retrying so the operation is applied once.

## Resources

Read-only JSON snapshots, available with `management:read`. Content follows the same visibility rules
as the tools.

| URI | Content |
| --- | --- |
| `webguard://monitorings` | First 100 monitorings visible to the user. |
| `webguard://monitorings/{id}` | One monitoring (template). |
| `webguard://status-pages` | Managed status pages. |
| `webguard://status-pages/{id}` | One status page (template). |
| `webguard://incidents/open` | Open incidents with diagnoses. |
| `webguard://dashboard` | Dashboard summary. |

## Prompts

Prompts are instruction templates. They contain no customer data and perform no action by themselves.

| Prompt | Arguments | Purpose |
| --- | --- | --- |
| `investigate_incident` | `incident_id` | Explain an incident from its diagnosis and analytics, read-only. |
| `monitoring_health_review` | `days`? | Review uptime, response times, and open incidents across monitorings, read-only. |
| `draft_status_page_update` | `status_page_id`, `incident_id` | Draft a customer-facing update and post it only after explicit confirmation. |

## Example session

```text
User:  Pause the "Staging API" monitoring and tell me which status pages show it.
Agent: list_monitorings            -> finds the ID
       get_monitoring              -> reads the current definition
       update_monitoring           -> sends the full definition with status "paused"   (WRITE)
       list_status_pages / get_status_page -> checks the components
```

A raw JSON-RPC call looks like this (after `initialize` and `notifications/initialized`, passing the
returned `Mcp-Session-Id` header):

```json
{"jsonrpc": "2.0", "id": 7, "method": "tools/call",
 "params": {"name": "create_monitoring_group", "arguments": {"name": "Production"}}}
```

## Security notes

- Use a dedicated key per assistant or integration, with the minimum abilities, and rotate it when a
  person leaves or a client is replaced.
- Prefer `management:read` for assistants that only analyse. Add `management:write` only for agents
  that should change configuration, and require human confirmation in the client for destructive tools.
- Tool results are data. Treat text returned from monitored systems (for example response bodies or
  incident messages) as untrusted input in the assistant: it must never be followed as instructions.
- The endpoint validates the `Host` header against `APP_URL` (DNS-rebinding protection). Serve it over
  HTTPS only.
- See the [REST API guide](api/rest-api.md) for the identical authorization and error behaviour of the
  routes behind each tool, and `php artisan openapi:generate` for the contract that the tests keep in
  sync with the tool catalogue.
