# Installation and Operations

## Screenshot demo data

Start the local Docker environment with `./start-dev.sh`. In another terminal, seed or reset the anonymized screenshot workspace:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml exec php php artisan webguard:seed-screenshot-demo
```

Open the local app and choose **Demo** on the sign-in page, or sign in with:

- Email: `demo.operator@example.test`
- Password: `password`

The demo operator can switch between English and German in the app's language settings and choose light, dark, or system theme in appearance settings. Running the seed command again replaces only this demo workspace and refreshes relative dates so the newest check remains recent. Mail uses the local Mailpit service; seeded notification channel deliveries are marked skipped and push credentials are empty, so the fixture does not send email, push, Slack, or webhook traffic.

To reset the fixture, run the same seed command again. The seeded API reference entry is a disabled placeholder; it is not a usable API credential.

To read the Core version used by a checkout, run `git describe --tags --always` from the Core repository. A running deployment can report its exact build revision only when its deployment exposes a version endpoint or build metadata; compare that revision to the displayed release tag before capturing screenshots.

## Prerequisites

- PHP 8.5 or higher
- Composer
- Bun
- A supported database, such as MySQL or PostgreSQL
- Redis

## Native Setup Without Docker

Use this setup when you prefer running services directly on your host machine.

1. Clone the repository.

   ```bash
   git clone https://github.com/m-breuer/webguard.git
   cd webguard
   ```

2. Install dependencies.

   ```bash
   composer install
   bun install
   ```

3. Create and configure the environment file.

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   For a native setup, change `DB_HOST`, `REDIS_HOST`, `CACHE_STORE`, and `QUEUE_CONNECTION` away from the Docker defaults from `.env.example`.

4. Run migrations.

   ```bash
   php artisan migrate
   ```

5. Build the SvelteKit frontend.

   ```bash
   bun run frontend:build
   ```

6. Run the test suite.

   ```bash
   php artisan test
   ```

## Heartbeat Queue Worker

In production, run a dedicated worker for the configured heartbeat queue on the standard Redis connection. If `HEARTBEAT_QUEUE` is not set, it defaults to `heartbeat`.

```bash
php artisan queue:work redis --queue="${HEARTBEAT_QUEUE:-heartbeat}" --sleep=3 --tries=3 --max-time=3600
```

Docker worker processes handle `default,heartbeat` by default.

## Docker Deployment

This repository uses two Docker modes:

- `docker-compose.yml`: standard deployment stack
- `docker-compose.override.yml`: local development additions only

The standard deployment stack always contains:

- `php`
- `frontend`
- `gateway`
- `schedule`
- `queue-default`

Every deployment also includes persistent infrastructure:

- `mysql`
- `redis`

Use `.env.example` as the starting point for `.env`.

Minimum `.env` fields for production:

```env
APP_URL=https://webguard.example.com
APP_KEY=base64:...
WEBGUARD_NETWORK=coolify
DOCKER_SSL_MODE=off

DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=webguard_core
DB_USERNAME=webguard
DB_PASSWORD=super-secret-password
MARIADB_ROOT_PASSWORD=another-super-secret-password

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_USERNAME=null
REDIS_PASSWORD=super-secret-redis-password

MAIL_HOST=mail.example.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
SMTP_USERNAME=mailer-user
MAIL_PASSWORD=mailer-password
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=WebGuard
```

`DB_PASSWORD`, `REDIS_PASSWORD`, and `MARIADB_ROOT_PASSWORD` are required production secrets.
Never reuse the local development values from `docker-compose.override.yml`.

For a non-Coolify Docker deployment, set `APP_URL` to the canonical public URL; Compose passes it to Laravel. SvelteKit derives the request origin from the gateway's forwarded host and protocol headers, with the gateway preserving an explicit host port. Set concrete values for SMTP as well, for example `SMTP_USERNAME=noreply@example.com` and `MAIL_FROM_NAME=WebGuard`. Do not use values such as `MAIL_USERNAME=${MAIL_FROM_ADDRESS}` because Docker Compose evaluates the env file during build and can warn or substitute empty strings before the container starts.

For Docker deployments, use `SMTP_USERNAME` as the input variable. The Compose file passes it into the container as Laravel's `MAIL_USERNAME`. This avoids Docker Compose expanding old values such as `MAIL_USERNAME=${MAIL_FROM_ADDRESS}` during Coolify builds.

If Coolify still shows `MAIL_USERNAME`, delete it or replace it with a literal value. Do not keep `MAIL_USERNAME=${MAIL_FROM_ADDRESS}` in Coolify; Docker Compose expands the env file before the application starts and logs a warning when the referenced value is not available at that point.

For SMTP on port `465`, set `MAIL_ENCRYPTION=ssl`. For SMTP on port `587`, set `MAIL_ENCRYPTION=tls`.

Optional:

- `MARKETING_URL` for the required canonical landing page, including its imprint, terms of use, and privacy policy

### Build and Startup Performance

The production Dockerfile uses BuildKit cache mounts for Composer and Bun dependency downloads. Keep BuildKit enabled in Docker, Docker Compose, or your deployment platform so repeated builds can reuse those caches.

The application keeps crawler access disabled by default. Its dynamic `robots.txt` permits `/status/` and `/sitemap.xml`, blocks subscriber token paths, and advertises the sitemap; the sitemap includes only status pages with `is_public` enabled. Published status pages render server-side with indexable metadata, while legacy monitoring-label pages remain `noindex`. Laravel responses retain the `X-Robots-Tag` no-index policy except for the crawler policy and sitemap routes. Set `APP_URL` to the canonical public URL so the sitemap advertises the correct absolute address.

```env
AUTORUN_LARAVEL_SCRIBE_GENERATE=false
```

Scribe generation remains optional and is unrelated to public search indexing.

### Bundled Database and Redis

Every Compose deployment creates MariaDB and Redis with persistent named volumes.
Application services always connect to `webguard-mariadb:3306` and `webguard-redis:6379`; external
host overrides and infrastructure profiles are no longer supported.
The service name `mysql` and Laravel driver `mysql` remain for compatibility;
the database image is MariaDB. Its new `mariadb_data` volume must be populated
with a logical restore. Never attach an existing MySQL data directory.
Keep the Compose project identity and volume mappings stable across deployments.
The existing `WEBGUARD_NETWORK` must exist (usually `coolify` in Coolify).
Keep secrets in Coolify runtime environment variables.

### Migrating existing Coolify services

Plan a maintenance window. Do not deploy the changed stack directly: PHP runs
migrations automatically at startup. Perform these steps on the deployment server
using the production Compose file only, never the development override.

1. Record the old database engine/version, database name, Redis version, logical
   database indexes, key prefix, passwords, container IDs, volume mappings and
   Coolify settings. The target is MariaDB 12.1.2 and Redis 8.4. Test a logical restore
   into an isolated target before the final migration;
   never attach the old database volume to the new image.
2. Keep the existing `APP_KEY`, application URL and database name. Back up private
   application storage and other mounted files separately. Preserve the old
   database and Redis resources and their volumes for rollback.
3. Stop incoming writes: enable maintenance mode, block API/instance ingestion
   at the proxy, stop the scheduler, then let active queue jobs finish and stop
   all workers. Include other applications writing to the same database/Redis.
   Maintenance mode alone does not stop background or external writers.
4. Create a restricted backup directory (`umask 077`). Take a final logical
   database dump using the source engine's tools, including triggers, routines,
   events and binary-safe data. For MariaDB with transactional tables, for example:
   `mariadb-dump --single-transaction --routines --events --triggers --hex-blob --no-tablespaces -u USER -p DATABASE > database.sql`.
   Run the client inside the source container or a compatible client container;
   use an interactive password prompt or protected client configuration.
   Verify exit status, backup size and a test restore. Nontransactional tables
   require an appropriate lock/backup strategy.
5. Preserve Redis queues, delayed/reserved jobs and all configured logical
   databases. With all writers stopped, create and validate a final RDB snapshot
   using the source Redis backup procedure. Copy it off the source volume.
   Do not use `FLUSHALL`, `queue:clear` or `optimize:clear` during migration.
   For shared Redis, do not import unrelated applications' data blindly:
   arrange an isolated, verified transfer of the required keys instead.
6. In Coolify prepare the new Compose definition and strong `DB_PASSWORD`,
   `MARIADB_ROOT_PASSWORD` and `REDIS_PASSWORD`. Remove obsolete external
   `DB_HOST`, `DB_PORT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME` and
   `COMPOSE_PROFILES` overrides. Keep the old values in the rollback record.
   Do not allow Coolify to start the full application yet.
7. From the exact deployment checkout/environment, start only infrastructure:
   `docker compose -f docker-compose.yml up -d mysql redis`.
   Confirm these resolve to new, empty persistent volumes and both services
   become healthy. Never run this in a different project by accident.
8. Import the SQL dump into the new MariaDB service before starting PHP:
   `docker compose -f docker-compose.yml exec -T mysql sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u "$MARIADB_USER" "$MARIADB_DATABASE"' < database.sql`.
   For privileged objects, use an appropriately privileged protected client
   configuration. Check import errors and compare table counts and key records.
9. Stop the new Redis service. Restore the verified RDB as `/data/dump.rdb` in
   its new volume using a temporary container, with ownership matching the Redis
   image. Preserve any original target files separately. Start Redis and confirm
   snapshot loading, logical databases, key counts and queue keys. Rehearse this
   restore with the actual source version before the maintenance window.
10. Start PHP alone; its startup runs `php artisan migrate --force` inside the
    container. Check migration status and logs. Then start frontend and gateway,
    verify health, login, monitoring history and persisted files while writes
    remain blocked. Start queue workers and scheduler, check both `default` and
    `heartbeat` queues, then lift maintenance and reopen ingestion.
11. Keep old resources stopped and backups retained until the new deployment has
    passed application checks and a fresh backup/restore check. Remove old
    resources only after explicit operational sign-off. Never use `down -v`.

For a large database already running **exactly MariaDB 12.1.2**, a cold physical
copy into a new volume is an alternative to steps 7–8. Stop all writers and shut
down the source database cleanly; verify a successful exit. Stop the empty target,
copy the complete source data directory with ownership and permissions preserved,
and keep the source volume unchanged. Start the target with the same image version
and compatible server configuration. Existing database users and passwords come
from the copied data, so initialization environment variables do not reset them;
reconcile the root credential with the configured secret and verify application
login and the image healthcheck. Validate every table before opening traffic.
Keep the original stopped database and the independently verified logical export.
Do not use this procedure across database engines or versions.

Rollback before reopening writes: stop all new application services, restore the
previous Compose revision and Coolify connection settings, restart old services,
and verify health before reopening traffic. After new writes have been accepted,
rollback requires reconciliation or a fresh reverse migration; switching back
would lose those writes. Do not run old application code against a schema changed
by new migrations without checking compatibility.

For Coolify routing, set `APP_URL=https://app.webguard.dev` explicitly. In **Domains for gateway**, set `https://app.webguard.dev:8080`; the `:8080` identifies the gateway's internal container port, while visitors still use normal HTTPS on port 443. This is the only domain to configure: `php` and `frontend` do not declare public Compose ports and are reached only through the gateway's internal Docker-network forwarding.

Do not add `SERVICE_URL_GATEWAY`, `SERVICE_URL_GATEWAY_8080`, `SERVICE_FQDN_GATEWAY`, or `SERVICE_FQDN_GATEWAY_8080` to this deployment. Those are Coolify-managed magic variables; referencing one makes Coolify generate and manage a service URL. Laravel uses the explicit `APP_URL`; SvelteKit derives each request's origin from the forwarded host and protocol supplied by the gateway.

Do not set a `SERVICE_FQDN_GATEWAY*` variable to `${APP_URL}`. `APP_URL` is a complete URL such as `https://app.webguard.dev`, whereas an FQDN contains only a hostname (and, for Coolify's internal routing selection, an optional container port). More importantly, the `SERVICE_FQDN_*` and `SERVICE_URL_*` names are Coolify magic-variable triggers: using either name causes Coolify to create a generated domain instead of reusing `APP_URL`.

If a previous deployment still shows a generated `gateway-<id>...` host, remove all custom `SERVICE_URL_GATEWAY*` and `SERVICE_FQDN_GATEWAY*` entries from Coolify's environment-variable editor, save the current Compose definition, and deploy again. Then set **only** the gateway domain to `https://app.webguard.dev:8080`; remove every other gateway-domain entry and leave the `php` and `frontend` domain fields empty. Coolify may display generated `SERVICE_*` values after this, but they are managed output from the gateway domain and must not be copied back into the environment. The generated gateway URL and FQDN should then resolve to the canonical `app.webguard.dev` domain.

Do not add custom Traefik labels that reference `SERVICE_FQDN_PHP` or Docker Compose defaults such as `${SERVICE_FQDN_PHP:-webguard.example.com}` in Coolify. Coolify generates the proxy routing and Let's Encrypt labels from the assigned domain; shipping custom labels can leave unresolved placeholders in Traefik and cause invalid `HostSNI` or ACME identifiers.

The gateway listens internally on `8080` for public HTTP routing. Laravel remains internal on `8080` and `8443` for optional container-level HTTPS. For Coolify deployments, keep `DOCKER_SSL_MODE=off` and let Coolify/Traefik generate and terminate public TLS.

The gateway preserves a valid `X-Forwarded-Proto` from Coolify and falls back
to its own scheme for direct HTTP traffic. Keep the gateway behind Coolify's
proxy and do not expose the gateway container directly on a host port.

If you use Coolify, Traefik, or another reverse proxy in front of the deployment, route traffic to the `gateway` service on `8080`. Set `DOCKER_SSL_MODE=mixed` and use Laravel port `8443` only when you explicitly want encrypted traffic between the gateway and the application container.

### Health Check

Docker Compose defines a health check for each runtime service so Coolify can report each container's health. Laravel checks its framework health route at `http://127.0.0.1:8080/status`; the frontend checks `/_health/frontend`; and the gateway checks its own endpoint plus the frontend and Laravel routes it serves. The scheduler runs `php artisan schedule:work`, which evaluates the schedule at the start of every minute without drifting; its health check verifies that Laravel can load the schedule. The queue worker uses `healthcheck-queue`. MariaDB and Redis always have service-level checks. Coolify's standard application Healthcheck page does not apply to Git-based Compose applications; keep these checks in the Compose definition. The `gateway` service remains the only public entry point.

## Docker Local Development

The local override adds everything that should only exist during development:

- Traefik
- built SvelteKit server for browser-stable local testing
- Gateway
- MariaDB
- Redis
- Mailpit
- bind mounts for the application code

### Local Setup

1. Clone and enter the repository.

   ```bash
   git clone https://github.com/m-breuer/webguard.git
   cd webguard
   ```

2. Create your local environment file.

   ```bash
   cp .env.example .env
   ```

3. Add a hosts entry for the local domain.

   ```text
   127.0.0.1 webguard.test
   127.0.0.1 mailpit.webguard.test
   ```

4. Start the local stack.

   ```bash
   ./start-dev.sh
   ```

5. Initialize Laravel once.

   ```bash
   docker compose -f docker-compose.yml -f docker-compose.override.yml exec php php artisan key:generate
   docker compose -f docker-compose.yml -f docker-compose.override.yml exec php php artisan migrate --seed
   ```

   Seeding in the `local` environment also creates an admin, a demo, and a regular member account (see `database/seeders/UserSeeder.php`), all with password `password`.

`start-dev.sh` builds the local stack, starts it, and opens a shell inside the `php` container.

The local frontend uses the built SvelteKit server rather than Vite HMR. Re-run `./start-dev.sh` after frontend changes so the local browser receives the rebuilt assets.

### Local URLs

- App: [http://webguard.test](http://webguard.test)
- HTTPS app: [https://webguard.test](https://webguard.test)
- Mailpit UI: [http://mailpit.webguard.test](http://mailpit.webguard.test)

### Local Environment Values

`.env.example` is the only Docker template.
Minimum `.env` fields for local Docker:

```env
APP_NAME=WebGuard
APP_ENV=local
APP_DEBUG=true
APP_URL=http://webguard.test
MARKETING_URL=http://localhost:4321
APP_KEY=base64:...
APP_TIMEZONE=Europe/Berlin
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=webguard_core
DB_USERNAME=webguard
DB_PASSWORD=webguard

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_USERNAME=null
REDIS_PASSWORD=null
HEARTBEAT_QUEUE=heartbeat
MONITORING_REGIONAL_CONSENSUS_FRESHNESS_MINUTES=10

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_ENCRYPTION=null
MAIL_USERNAME=null
SMTP_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=noreply@webguard.test
MAIL_FROM_NAME=WebGuard

DOCKER_APP_HOST=webguard.test
DOCKER_MAILPIT_HOST=mailpit.webguard.test
DOCKER_HTTP_PORT=80
DOCKER_HTTPS_PORT=443
DOCKER_MYSQL_PORT=3306
DOCKER_MAILPIT_SMTP_PORT=1025
DOCKER_MAILPIT_UI_PORT=8025
DOCKER_SSL_MODE=off
WEBGUARD_NETWORK=webguard-network
AUTORUN_LARAVEL_SCRIBE_GENERATE=false
```

If you already have an older `.env`, align it with `.env.example` before using Docker.

### Planned maintenance email delivery

Planned maintenance saves a notice for each verified public status-page subscriber in
the same transaction as the maintenance change. The scheduler dispatches pending
notices every minute to the existing `default` queue. Run both the scheduler and a
default-queue worker in production.

Each recipient has at most five delivery attempts, with delays of one minute,
five minutes, fifteen minutes, and one hour after successive failures. Failed
enqueueing is retried on the next scheduler run; an interrupted dispatch or worker
is recoverable after a five-minute lease. Subscribers who unsubscribe or lose
verification, and status pages made private, are excluded before sending.

To dispatch due notices manually:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml exec php php artisan notifications:dispatch-planned-maintenance
```

Inspect `status_page_maintenance_deliveries` for delivery state. Unsent,
uncancelled rows with `attempts = 5` have exhausted automatic retries; after
resolving the mail failure, an operator can reset their `attempts`, `available_at`,
and `queued_at` to retry them. Logs identify delivery IDs without subscriber
addresses or SMTP exception details. Existing records without a saved payload
are retained but cannot be automatically replayed.

Email delivery is at least once: a worker crash after SMTP accepts a message but
before `sent_at` is saved may cause a duplicate. Deploy the migration before
restarting application, scheduler, and queue workers.

### Local Commands

Run migrations:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml exec php php artisan migrate
```

Run one queue job:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml exec queue-default php artisan queue:work redis --once
```

Build the SvelteKit frontend image:

```bash
docker build --target sveltekit_build .
```

Install frontend dependencies:

```bash
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app oven/bun:1 bun install --cwd frontend
```

Run the browser end-to-end tests (needs the built frontend, nginx, and Playwright
Chromium; see [Test Concept](test-concept.md) for details):

```bash
docker run --rm -v "$PWD:/app" -w /app oven/bun:1 sh -c 'bun install --frozen-lockfile && bun run frontend:build'
docker build --target ci -t webguard-browser-tests .
docker run --rm -v "$PWD:/var/www/html" -w /var/www/html webguard-browser-tests sh -c \
  'apt-get update && apt-get install -y --no-install-recommends nginx \
   && ./node_modules/.bin/playwright install --with-deps chromium \
   && composer install --no-interaction && composer test:browser'
```

## webguard-instance Integration With Local Docker

The local stack uses the shared Docker network `webguard-network`.
Because the local Traefik service also has the network alias `webguard.test`, other containers on the same network can reach WebGuard through the same URL as your browser.

That means `webguard-instance` can use either local Core hostname with the
single instance base URL:

- `http://webguard.test/api/instances`
- `http://webguard-core/api/instances`

The required contract rules are documented in the [WebGuard
Instance API contract](integrations/webguard-instance-api.md).

Example:

```yaml
services:
  webguard-instance:
    networks:
      - webguard-network
    environment:
      WEBGUARD_CORE_API_URL: http://webguard.test/api/instances

networks:
  webguard-network:
    external: true
```
