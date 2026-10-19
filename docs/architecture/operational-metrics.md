# Operational metrics

WebGuard Core records low-cardinality operational metrics in Redis and emits a
structured log summary once per minute. The metrics stay inside the Core
operational boundary and are not added to browser, scanner-instance, external
API, or public status responses.

The scheduled `webguard:report-operational-metrics` command reports the previous
complete minute and checks the configured default and heartbeat queue lengths.
It can also be run manually. Redis stores one-minute counters for two hours so a
late scheduler run can still report its window.

The summary log event is `webguard.operational_metrics.window`. It includes API
request count and average duration, slow API request count, cache hit rate,
processed job count and average queue lag, slow job count, scheduled-task count
and average duration, and slow-query count and total duration. Separate warning
events identify slow API routes, queue lag, slow scheduled commands, and slow
queries. Queue backlog is reported as
`webguard.operational_metrics.queue_backlog`; a backlog at or above the
configured threshold also emits a warning.

Thresholds live in `config/operational_metrics.php`: queries at 500 ms, API
requests at 1,000 ms, queue lag at 60 seconds, queue backlog at 100 jobs, and
scheduled tasks at 5 seconds. Adjust these defaults to match deployment
capacity and the log aggregation window.

Slow-query logs contain a stable fingerprint and connection name, never the SQL
text or bindings. API logs use the route name and method, not the request URL,
headers, body, or token. Cache metrics contain hit and miss counts without cache
keys. Metric collection is best-effort; Redis failures do not interrupt API,
queue, or scheduler work.
