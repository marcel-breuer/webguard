# Features

WebGuard helps teams observe public websites, operational tasks, certificates, domains, and customer-facing availability from one management interface.

## Monitoring

- **Uptime monitoring:** keep a close eye on website availability with asynchronous uptime checks.
- **Heartbeat and cron monitoring:** detect stalled cron jobs, workers, and background tasks with private heartbeat ping URLs.
- **Server health monitoring:** accept pushed CPU, RAM, storage, load, uptime, and custom metric reports with configurable per-monitor usage thresholds.
- **Response time tracking:** monitor website performance by recording response times and optionally alert after a configurable number of consecutive slow, successful HTTP or keyword checks. These performance alerts are separate from availability incidents.
- **Expected HTTP status ranges:** define accepted HTTP status codes or ranges per HTTP or keyword monitoring, such as `200-299, 301, 302`.
- **DNS record monitoring:** track expected `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `NS`, `SOA`, or `CAA` records and alert through the normal incident flow when a monitoring instance reports a mismatch.
- **SSL certificate monitoring:** receive warnings before certificates expire.
- **Domain expiration monitoring:** track domain registration expiry and receive proactive renewal warnings before critical domains lapse.
- **Customizable checks:** configure HTTP method, body, and headers for monitoring checks.
- **Consecutive failure confirmation:** require multiple failed checks in a row before an incident opens, reducing noise from transient outages.
- **Recurring maintenance windows:** schedule weekly or monthly maintenance for individual monitorings or monitoring groups with timezone-aware occurrence handling.
- **Regional consensus incidents:** combine fresh results from every selected monitoring location before declaring an outage. A single failed location is shown as a localized degradation without paging users, a strict majority opens a regional incident, and unanimous failures classify the incident as global. The monitoring detail view shows the current state of every location so operators can distinguish service outages from regional DNS, routing, CDN, or probe problems.

## Product Surface

- **Real-time dashboard:** visualize monitoring data with statistics and charts.
- **Monitoring management:** create and edit monitorings through structured forms, assign them to monitoring groups, and manage ownership with team-admin controls.
- **Team collaboration:** create teams, invite registered or new users by email, assign admin/member roles, and share team-owned monitorings with read-only members.
- **Admin panel:** manage users, subscription packages, and API usage logs.
- **REST API:** access monitoring data, manage teams, and create or move team monitorings programmatically.
- **Mobile app:** use the implemented WebGuard mobile app from the separate [WebGuard App Repository](https://github.com/marcel-breuer/webguard-app).
- **SLA badge:** display website monitoring status on external sites with a compact SLA badge that shows live status, uptime proof, and public trust context.
- **Public status pages:** publish monitoring status, uptime, a calculated 30-day aggregate uptime calendar for multi-monitoring pages, recent incidents, active or upcoming maintenance windows, and operator-managed announcements for users and customers. Components can select individual monitorings or dynamically use an existing monitoring group. Verified subscribers receive proactive notices when affected planned maintenance is scheduled or an operator elects to notify them about an announcement. New public status page URLs use ULIDs, while legacy slug URLs remain available through compatibility routes.
- **Private incident reviews:** record the problem and resolution for each status-page incident in the management interface without exposing internal notes on public status pages.
- **Incident follow-ups and timelines:** track private preventive actions, owners, due dates, and custom timeline events alongside automatically collected incident activity.
- **Incident analytics:** classify incidents by type, severity, customer impact, and affected service, then review recurrence and average recovery time for the selected period.
- **Global language switch:** switch between supported languages from both public and authenticated top navigation.

## Notifications

- **Flexible notifications:** receive notifications for status changes, SSL expiry, and domain expiry through in-app notifications and configurable Slack, Telegram, Discord, Microsoft Teams, and webhook channels.
- **Per-user team notifications:** each team member keeps their own notification channel choices and read state for shared team monitorings.
- **Weekly monitoring digest:** email weekly uptime, incident, downtime, SSL, and domain expiry summaries to active users.
