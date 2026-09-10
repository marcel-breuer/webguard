# Installation and Operations

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

The optional `internal-services` profile also adds bundled infrastructure:

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
MYSQL_ROOT_PASSWORD=another-super-secret-password

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

`DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, and `REDIS_PASSWORD` are required
production secrets. `MYSQL_ROOT_PASSWORD` is used only when the bundled
`internal-services` MySQL profile is enabled; use the database provider's
corresponding credentials when MySQL and Redis are managed externally. Never
reuse the local development values from `docker-compose.override.yml`.

For a non-Coolify Docker deployment, set `APP_URL` to the canonical public URL. The Compose file passes the same value to Laravel and SvelteKit as `ORIGIN`. Set concrete values for SMTP as well, for example `SMTP_USERNAME=noreply@example.com` and `MAIL_FROM_NAME=WebGuard`. Do not use values such as `MAIL_USERNAME=${MAIL_FROM_ADDRESS}` because Docker Compose evaluates the env file during build and can warn or substitute empty strings before the container starts.

For Docker deployments, use `SMTP_USERNAME` as the input variable. The Compose file passes it into the container as Laravel's `MAIL_USERNAME`. This avoids Docker Compose expanding old values such as `MAIL_USERNAME=${MAIL_FROM_ADDRESS}` during Coolify builds.

If Coolify still shows `MAIL_USERNAME`, delete it or replace it with a literal value. Do not keep `MAIL_USERNAME=${MAIL_FROM_ADDRESS}` in Coolify; Docker Compose expands the env file before the application starts and logs a warning when the referenced value is not available at that point.

For SMTP on port `465`, set `MAIL_ENCRYPTION=ssl`. For SMTP on port `587`, set `MAIL_ENCRYPTION=tls`.

Optional:

- `MARKETING_URL` for the required canonical landing page, including its imprint, terms of use, and privacy policy

### Build and Startup Performance

The production Dockerfile uses BuildKit cache mounts for Composer and Bun dependency downloads. Keep BuildKit enabled in Docker, Docker Compose, or your deployment platform so repeated builds can reuse those caches.

The production web container ships a static `robots.txt` that disallows all crawlers. The app also sends an `X-Robots-Tag` header on every response. SEO metadata, sitemap generation, and crawler-facing routes are intentionally not part of the core app; public discovery belongs to the separately deployed marketing website.

```env
AUTORUN_LARAVEL_SCRIBE_GENERATE=false
```

Scribe generation remains optional and is unrelated to public search indexing.

### External Database and Redis

For production deployments such as Coolify, set `DB_HOST` and `REDIS_HOST` to the service names or hostnames of the database and Redis containers you want to use.

Example with external services in the same Docker network:

```env
WEBGUARD_NETWORK=coolify

DB_HOST=my-production-mysql
DB_PORT=3306
DB_DATABASE=webguard_core
DB_USERNAME=webguard
DB_PASSWORD=...

REDIS_HOST=my-production-redis
REDIS_PORT=6379
REDIS_PASSWORD=null
```

`WEBGUARD_NETWORK` must be the existing external Docker network shared by WebGuard, MySQL, Redis, and any webguard-instance containers. In Coolify this is usually `coolify`; use the actual shared network name from your server if it differs.

The bundled `mysql` and `redis` services are behind the `internal-services` Compose profile. Leave `COMPOSE_PROFILES` empty in Coolify when you connect external services. Set `COMPOSE_PROFILES=internal-services` only when this Compose stack should also create the bundled MySQL and Redis containers.

Coolify detects variables that are referenced in `docker-compose.yml` and exposes them in its UI. Keep production secrets as runtime variables in Coolify and override `DB_HOST`, `DB_PASSWORD`, `REDIS_HOST`, `REDIS_PASSWORD`, and mail credentials there.

For Coolify routing, set `APP_URL=https://app.webguard.dev` explicitly. In **Domains for gateway**, set `https://app.webguard.dev:8080`; the `:8080` identifies the gateway's internal container port, while visitors still use normal HTTPS on port 443. This is the only domain to configure: `php` and `frontend` do not declare public Compose ports and are reached only through the gateway's internal Docker-network forwarding.

Do not add `SERVICE_URL_GATEWAY`, `SERVICE_URL_GATEWAY_8080`, `SERVICE_FQDN_GATEWAY`, or `SERVICE_FQDN_GATEWAY_8080` to this deployment. Those are Coolify-managed magic variables; referencing one makes Coolify generate and manage a service URL. The Compose stack deliberately uses the explicit `APP_URL` for both Laravel and SvelteKit so it remains the single canonical origin across deploys.

Do not set a `SERVICE_FQDN_GATEWAY*` variable to `${APP_URL}`. `APP_URL` is a complete URL such as `https://app.webguard.dev`, whereas an FQDN contains only a hostname (and, for Coolify's internal routing selection, an optional container port). More importantly, the `SERVICE_FQDN_*` and `SERVICE_URL_*` names are Coolify magic-variable triggers: using either name causes Coolify to create a generated domain instead of reusing `APP_URL`.

If a previous deployment still shows a generated `gateway-<id>...` host, remove all custom `SERVICE_URL_GATEWAY*` and `SERVICE_FQDN_GATEWAY*` entries from Coolify's environment-variable editor, save the current Compose definition, and deploy again. Then set **only** the gateway domain to `https://app.webguard.dev:8080`; remove every other gateway-domain entry and leave the `php` and `frontend` domain fields empty. Coolify may display generated `SERVICE_*` values after this, but they are managed output from the gateway domain and must not be copied back into the environment. The generated gateway URL and FQDN should then resolve to the canonical `app.webguard.dev` domain.

Do not add custom Traefik labels that reference `SERVICE_FQDN_PHP` or Docker Compose defaults such as `${SERVICE_FQDN_PHP:-webguard.example.com}` in Coolify. Coolify generates the proxy routing and Let's Encrypt labels from the assigned domain; shipping custom labels can leave unresolved placeholders in Traefik and cause invalid `HostSNI` or ACME identifiers.

The gateway listens internally on `8080` for public HTTP routing. Laravel remains internal on `8080` and `8443` for optional container-level HTTPS. For Coolify deployments, keep `DOCKER_SSL_MODE=off` and let Coolify/Traefik generate and terminate public TLS.

The gateway preserves a valid `X-Forwarded-Proto` from Coolify and falls back
to its own scheme for direct HTTP traffic. Keep the gateway behind Coolify's
proxy and do not expose the gateway container directly on a host port.

If you use Coolify, Traefik, or another reverse proxy in front of the deployment, route traffic to the `gateway` service on `8080`. Set `DOCKER_SSL_MODE=mixed` and use Laravel port `8443` only when you explicitly want encrypted traffic between the gateway and the application container.

### Health Check

Laravel checks its framework health route at `http://127.0.0.1:8080/status`. SvelteKit checks `/_health/frontend`, and the gateway checks `/_health/gateway`; all health probes must return a `2xx` response. The `gateway` service is the only public entry point. Docker Compose retains the PHP health check and adds independent frontend and gateway checks; the queue worker remains an explicit `worker` build target.

## Docker Local Development

The local override adds everything that should only exist during development:

- Traefik
- built SvelteKit server for browser-stable local testing
- Gateway
- MySQL
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
COMPOSE_PROFILES=internal-services
AUTORUN_LARAVEL_SCRIBE_GENERATE=false
```

If you already have an older `.env`, align it with `.env.example` before using Docker.

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
