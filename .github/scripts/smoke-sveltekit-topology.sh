#!/usr/bin/env sh

set -eu

project_name="${SMOKE_PROJECT_NAME:-webguard-sveltekit-smoke}"
network_name="${WEBGUARD_NETWORK:-${project_name}-network}"
compose="docker compose --project-name ${project_name} --profile internal-services -f docker-compose.yml"

cleanup() {
    $compose logs --no-color || true
    $compose down --volumes --remove-orphans || true
    docker network rm "$network_name" || true
}

trap cleanup EXIT INT TERM

docker network create "$network_name"

export WEBGUARD_NETWORK="$network_name"
export APP_KEY="${APP_KEY:-base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=}"
export MARKETING_URL="${MARKETING_URL:-https://marketing.webguard.test}"
export APP_URL="${APP_URL:-http://gateway:8080}"
export MAIL_MAILER="${MAIL_MAILER:-log}"
smoke_secret() {
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n'
}
export DB_PASSWORD="${DB_PASSWORD:-$(smoke_secret)}"
export MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-$(smoke_secret)}"
export REDIS_PASSWORD="${REDIS_PASSWORD:-$(smoke_secret)}"

$compose build php frontend gateway queue-default
$compose up --no-build --detach --wait --wait-timeout 180 php frontend gateway schedule queue-default mysql redis

$compose exec --no-TTY gateway wget --quiet --output-document=/dev/null http://127.0.0.1:8080/_health/gateway
$compose exec --no-TTY gateway wget --quiet --output-document=/dev/null http://127.0.0.1:8080/_health/frontend
$compose exec --no-TTY gateway wget --quiet --output-document=/dev/null http://127.0.0.1:8080/_health/laravel
$compose exec --no-TTY queue-default healthcheck-queue
$compose exec --no-TTY schedule php artisan schedule:list --no-interaction --no-ansi
$compose exec --no-TTY php php artisan db:seed --class=PackageSeeder --force --no-interaction

status_page_fixture="$(
    $compose exec --no-TTY php php -r '
        require "vendor/autoload.php";

        $application = require "bootstrap/app.php";
        $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $user = App\Models\User::query()->create([
            "name" => "SvelteKit Browser Smoke",
            "email" => "sveltekit-browser-smoke@example.test",
            "password" => bcrypt("not-used"),
            "role" => App\Enums\UserRole::REGULAR->value,
            "email_verified_at" => now(),
            "terms_accepted_at" => now(),
            "privacy_accepted_at" => now(),
        ]);

        $statusPage = App\Models\StatusPage::query()->create([
            "user_id" => $user->id,
            "name" => "SvelteKit Browser Smoke",
            "slug" => "sveltekit-browser-smoke",
            "description" => "Isolated browser smoke-test data.",
            "is_public" => true,
        ]);

        $statusPage->subscriptions()->create([
            "email" => "sveltekit-browser-smoke@example.test",
            "unsubscribe_token" => "sveltekit-browser-smoke-unsubscribe-token",
            "verified_at" => now(),
        ]);

        $statusPage->subscriptions()->create([
            "email" => "sveltekit-browser-smoke-confirmation@example.test",
            "confirmation_token_hash" => App\Models\StatusPageSubscription::hashToken("sveltekit-browser-smoke-confirmation-token"),
            "unsubscribe_token" => "sveltekit-browser-smoke-confirmation-unsubscribe-token",
        ]);

        echo $statusPage->id . "|sveltekit-browser-smoke-unsubscribe-token|" . $statusPage->slug . "|sveltekit-browser-smoke-confirmation-token";
    '
)"

status_page_id="${status_page_fixture%%|*}"
status_page_fixture_without_id="${status_page_fixture#*|}"
unsubscribe_token="${status_page_fixture_without_id%%|*}"
status_page_slug="${status_page_fixture_without_id#*|}"
confirmation_token="${status_page_slug#*|}"
status_page_slug="${status_page_slug%%|*}"

repository_path="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"

docker run --rm \
    --network "$network_name" \
    --env SMOKE_BASE_URL="http://gateway:8080" \
    --env SMOKE_STATUS_PAGE_ID="$status_page_id" \
    --env SMOKE_STATUS_PAGE_SLUG="$status_page_slug" \
    --env SMOKE_UNSUBSCRIBE_TOKEN="$unsubscribe_token" \
    --env SMOKE_CONFIRMATION_TOKEN="$confirmation_token" \
    --volume "$repository_path/frontend/scripts:/ms-playwright/smoke:ro" \
    --volume "$repository_path/node_modules:/ms-playwright/node_modules:ro" \
    mcr.microsoft.com/playwright:v1.63.0-noble \
    node /ms-playwright/smoke/smoke-public-status.mjs
