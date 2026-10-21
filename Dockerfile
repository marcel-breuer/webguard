# syntax=docker/dockerfile:1.7

############################################
# Base Image
############################################

# Learn more about the Server Side Up PHP Docker Images at:
# https://serversideup.net/open-source/docker-php/
FROM serversideup/php:8.5-fpm-nginx AS base

# Additional production PHP extensions
USER root
COPY docker/php/healthcheck.sh /usr/local/bin/webguard-healthcheck
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    set -eux; \
    chmod +x /usr/local/bin/webguard-healthcheck; \
    rm -f /etc/apt/apt.conf.d/docker-clean; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update; \
    apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd; \
    apt-mark manual libfreetype6 libjpeg62-turbo; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev; \
    php -m | grep -qx gd

############################################
# Development Image
############################################
FROM base AS development

# We can pass USER_ID and GROUP_ID as build arguments
# to ensure the www-data user has the same UID and GID
# as the user running Docker.
ARG USER_ID
ARG GROUP_ID

# Switch to root so we can set the user ID and group ID
USER root
RUN apt-get update && \
    apt-get install -y --no-install-recommends nodejs && \
    rm -rf /var/lib/apt/lists/* && \
    install-php-extensions sockets && \
    docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID
USER www-data

############################################
# CI image
############################################
FROM base AS ci

# Sometimes CI images need to run as root
USER root
COPY --from=oven/bun:1.3.11 /usr/local/bin/bun /usr/local/bin/bun
RUN apt-get update && \
    apt-get install -y --no-install-recommends nodejs && \
    rm -rf /var/lib/apt/lists/* && \
    install-php-extensions bz2 curl gmp intl ldap mbstring opcache pdo_mysql pdo_sqlite pspell redis snmp sockets sqlite3 tidy xdebug zip

############################################
# Production Image
############################################
FROM base AS app_build
# Install Composer
USER root
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
RUN chown www-data:www-data /app
USER www-data
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
COPY --link --chown=33:33 composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked,uid=33,gid=33 \
    composer install --no-dev --no-autoloader --no-scripts --no-interaction --no-progress --prefer-dist --ignore-platform-req=ext-redis
COPY --link --chown=33:33 . .
RUN rm -f bootstrap/cache/*.php && \
    composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

FROM oven/bun:1 AS sveltekit_build
WORKDIR /app
ENV BUN_INSTALL_CACHE_DIR=/tmp/bun-cache \
    PATH=/app/node_modules/.bin:${PATH}
COPY --link package.json bun.lock ./
COPY --link frontend/package.json frontend/package.json
RUN --mount=type=cache,target=/tmp/bun-cache,sharing=locked \
    bun install --frozen-lockfile --cwd frontend
COPY --link frontend frontend
RUN bun run frontend:build

FROM oven/bun:1-slim AS sveltekit_production
WORKDIR /app
ENV NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=3000
COPY --link --from=sveltekit_build /app/frontend/build ./build
COPY --link --from=sveltekit_build /app/frontend/package.json ./package.json
HEALTHCHECK --interval=10s --timeout=3s --start-period=15s --retries=6 CMD bun -e 'const response = await fetch("http://127.0.0.1:3000/_health/frontend"); process.exit(response.ok ? 0 : 1)'
CMD ["bun", "build/index.js"]

FROM serversideup/php:8.5-cli AS worker
# Copy application code from the build stage
COPY --link --from=app_build --chown=33:33 /app /var/www/html
USER www-data
WORKDIR /var/www/html

############################################
# Production Image
############################################
FROM base AS production
COPY --link --from=app_build --chown=33:33 /app /var/www/html
COPY --link docker/php/entrypoint.d/ /etc/entrypoint.d/
RUN chmod +x /etc/entrypoint.d/*.sh
HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=6 CMD ["webguard-healthcheck"]
USER www-data
WORKDIR /var/www/html
