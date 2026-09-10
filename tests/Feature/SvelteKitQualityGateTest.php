<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SvelteKitQualityGateTest extends TestCase
{
    public function test_sveltekit_operational_forms_guard_duplicate_submissions(): void
    {
        $singleSubmitComponents = [
            'frontend/src/lib/components/MonitoringForm.svelte',
            'frontend/src/lib/components/MonitoringGroupForm.svelte',
            'frontend/src/lib/components/StatusPageForm.svelte',
            'frontend/src/routes/(app)/maintenance/+page.svelte',
        ];

        foreach ($singleSubmitComponents as $singleSubmitComponent) {
            $contents = file_get_contents(base_path($singleSubmitComponent));

            $this->assertIsString($contents);
            $this->assertStringContainsString('if (submitting)', $contents, $singleSubmitComponent);
        }

        $monitoringOperations = file_get_contents(base_path('frontend/src/lib/components/MonitoringOperationsPanel.svelte'));
        $monitoringGroupForm = file_get_contents(base_path('frontend/src/lib/components/MonitoringGroupForm.svelte'));

        $this->assertIsString($monitoringOperations);
        $this->assertIsString($monitoringGroupForm);
        $this->assertStringContainsString('notificationsSaving)', $monitoringOperations);
        $this->assertStringContainsString('ownershipSaving)', $monitoringOperations);
        $this->assertStringContainsString('await onSuccess()', $monitoringGroupForm);
    }

    public function test_shared_checkbox_supports_multiple_values_in_a_bound_group(): void
    {
        $checkbox = file_get_contents(base_path('frontend/src/lib/components/Checkbox.svelte'));

        $this->assertIsString($checkbox);
        $this->assertStringContainsString('function toggleGroup', $checkbox);
        $this->assertStringContainsString('group = input.checked', $checkbox);
        $this->assertSame(2, mb_substr_count($checkbox, 'rounded-full'));
        $this->assertStringNotContainsString('bind:group', $checkbox);
    }

    public function test_monitoring_creation_uses_dialogs_instead_of_a_standalone_page(): void
    {
        $dashboard = file_get_contents(base_path('frontend/src/routes/(app)/dashboard/+page.svelte'));
        $monitorings = file_get_contents(base_path('frontend/src/routes/(app)/monitorings/+page.svelte'));

        $this->assertIsString($dashboard);
        $this->assertIsString($monitorings);
        $this->assertFileDoesNotExist(base_path('frontend/src/routes/(app)/monitorings/create/+page.svelte'));
        $this->assertFileDoesNotExist(base_path('frontend/src/routes/(app)/monitorings/create/+page.server.ts'));

        foreach ([$dashboard, $monitorings] as $page) {
            $this->assertStringContainsString('Dialog', $page);
            $this->assertStringContainsString('MonitoringForm', $page);
            $this->assertStringContainsString('presentation="edit-modal"', $page);
            $this->assertStringContainsString('requestFirstPartyApi<MonitoringFormOptions>("/api/monitorings/form-options")', $page);
            $this->assertStringNotContainsString('/monitorings/create', $page);
        }
    }

    public function test_sveltekit_components_use_tailwind_without_scoped_or_inline_styles(): void
    {
        foreach (File::allFiles(base_path('frontend/src')) as $file) {
            if ($file->getExtension() !== 'svelte') {
                continue;
            }

            $contents = $file->getContents();

            $this->assertStringNotContainsString('<style', $contents, $file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/\\sstyle\\s*=/i', $contents, $file->getPathname());
        }
    }

    public function test_shared_sveltekit_controls_provide_keyboard_and_focus_accessibility(): void
    {
        $appStyles = file_get_contents(base_path('frontend/src/app.css'));
        $appShell = file_get_contents(base_path('frontend/src/lib/components/AppShell.svelte'));
        $dialog = file_get_contents(base_path('frontend/src/lib/components/Dialog.svelte'));
        $select = file_get_contents(base_path('frontend/src/lib/components/Select.svelte'));

        $this->assertIsString($appStyles);
        $this->assertIsString($appShell);
        $this->assertIsString($dialog);
        $this->assertIsString($select);
        $this->assertStringContainsString(':focus-visible', $appStyles);
        $this->assertStringContainsString('html[data-dialog-open]', $appStyles);
        $this->assertStringContainsString('Skip to main content', $appShell);
        $this->assertStringContainsString('id="main-content"', $appShell);
        $this->assertStringContainsString('aria-current={isActive', $appShell);
        $this->assertStringContainsString('collapsed ? "justify-center px-0" : "gap-3 px-3"', $appShell);
        $this->assertStringContainsString('<span class={collapsed ? "sr-only" : ""}>{item.label}</span>', $appShell);
        $this->assertStringContainsString('aria-label={item.label} title={item.label}', $appShell);
        $this->assertStringContainsString('lockBackgroundScroll', $dialog);
        $this->assertStringContainsString('focusableElements', $dialog);
        $this->assertStringContainsString('event.key !== "Tab"', $dialog);
        $this->assertStringContainsString('handleTriggerKeydown', $select);
        $this->assertStringContainsString('handleOptionKeydown', $select);
        $this->assertStringContainsString('aria-label={searchPlaceholder}', $select);
    }

    public function test_sveltekit_locale_uses_the_laravel_translation_catalog_and_locale_aware_dates(): void
    {
        $localize = file_get_contents(base_path('frontend/src/lib/i18n/localize.ts'));
        $format = file_get_contents(base_path('frontend/src/lib/i18n/format.ts'));
        $germanTranslations = file_get_contents(base_path('resources/lang/de/sveltekit.php'));
        $layout = file_get_contents(base_path('frontend/src/routes/+layout.server.ts'));

        $this->assertIsString($localize);
        $this->assertIsString($format);
        $this->assertIsString($germanTranslations);
        $this->assertIsString($layout);

        foreach ([
            "'Application navigation' => 'Anwendungsnavigation'",
            "'Create monitoring' => 'Überwachung erstellen'",
            "'Search by name, target, port or keyword' => 'Nach Name, Ziel, Port oder Schlüsselwort suchen'",
            "'Response-time data will appear after the monitoring collects results.' => 'Antwortzeitdaten werden angezeigt, sobald die Überwachung Ergebnisse erfasst.'",
            "'Subscribe to updates' => 'Updates abonnieren'",
            "'Users could not be loaded.' => 'Benutzer konnten nicht geladen werden.'",
            "'Your session has expired. Sign in again to continue.' => 'Ihre Sitzung ist abgelaufen. Melden Sie sich erneut an, um fortzufahren.'",
            "'Ongoing' => 'Laufend'",
            "'Resolved incident' => 'Gelöster Vorfall'",
            "'Down' => 'Ausgefallen'",
            "'Last 7 Days' => 'Letzte 7 Tage'",
            "'Successful' => 'Erfolgreich'",
            "'Up' => 'Betriebsbereit'",
            "'By type' => 'Nach Typ'",
            "'By severity' => 'Nach Schweregrad'",
            "'By customer impact' => 'Nach Kundenauswirkung'",
            "'Services with more than one incident in this period.' => 'Dienste mit mehr als einem Vorfall in diesem Zeitraum.'",
            "'Try a broader period or remove one of the filters.' => 'Versuchen Sie es mit einem längeren Zeitraum oder entfernen Sie einen der Filter.'",
        ] as $translation) {
            $this->assertStringContainsString($translation, $germanTranslations);
        }

        $this->assertStringNotContainsString('const german:', $localize);
        $this->assertStringContainsString('/api/translations?locale=', $layout);

        $dashboard = file_get_contents(base_path('frontend/src/routes/(app)/dashboard/+page.svelte'));

        $this->assertIsString($dashboard);
        $this->assertStringNotContainsString('title="Seven-day uptime"', $dashboard);
        $this->assertStringNotContainsString('Availability across all visible monitorings.', $dashboard);

        $this->assertStringContainsString('page.data.locale === "de" ? "de-DE" : "en-US"', $format);
        $this->assertStringContainsString('new Intl.DateTimeFormat(interfaceLocale(), options)', $format);
        $this->assertStringContainsString('Number.isNaN(date.getTime()) ? fallback', $format);

        foreach ([
            'frontend/src/lib/components/MonitoringAnalytics.svelte',
            'frontend/src/routes/(app)/admin/activity-logs/+page.svelte',
            'frontend/src/routes/(app)/admin/api/+page.svelte',
            'frontend/src/routes/(app)/dashboard/+page.svelte',
            'frontend/src/routes/(app)/incidents/analytics/+page.svelte',
            'frontend/src/routes/(app)/maintenance/+page.svelte',
            'frontend/src/routes/(app)/monitorings/+page.svelte',
            'frontend/src/routes/(app)/monitorings/[id]/+page.svelte',
            'frontend/src/routes/(app)/notifications/+page.svelte',
            'frontend/src/routes/(app)/profile/+page.svelte',
            'frontend/src/routes/(app)/status-pages/[id]/+page.svelte',
            'frontend/src/routes/status/[id]/+page.svelte',
        ] as $dateTimeView) {
            $contents = file_get_contents(base_path($dateTimeView));

            $this->assertIsString($contents);
            $this->assertStringContainsString('formatDateTime', $contents, $dateTimeView);
            $this->assertStringNotContainsString('new Intl.DateTimeFormat(undefined', $contents, $dateTimeView);
            $this->assertStringNotContainsString('new Intl.DateTimeFormat("en-US"', $contents, $dateTimeView);
        }
    }

    public function test_shared_sveltekit_components_preserve_mobile_navigation_and_content_space(): void
    {
        $appShell = file_get_contents(base_path('frontend/src/lib/components/AppShell.svelte'));
        $dialog = file_get_contents(base_path('frontend/src/lib/components/Dialog.svelte'));
        $dataTable = file_get_contents(base_path('frontend/src/lib/components/DataTable.svelte'));
        $monitoringAnalytics = file_get_contents(base_path('frontend/src/lib/components/MonitoringAnalytics.svelte'));
        $monitoringForm = file_get_contents(base_path('frontend/src/lib/components/MonitoringForm.svelte'));

        $this->assertIsString($appShell);
        $this->assertIsString($dialog);
        $this->assertIsString($dataTable);
        $this->assertIsString($monitoringAnalytics);
        $this->assertIsString($monitoringForm);
        $this->assertStringContainsString('matchMedia("(max-width: 54rem)")', $appShell);
        $this->assertStringContainsString('mobileNavigationOpen', $appShell);
        $this->assertStringContainsString('inert={isMobileViewport && !mobileOpen}', $appShell);
        $this->assertStringContainsString('aria-controls="app-navigation"', $appShell);
        $this->assertStringContainsString('p-2 sm:p-4', $dialog);
        $this->assertStringContainsString('p-4 sm:p-6', $dialog);
        $this->assertStringContainsString('overscroll-x-contain', $dataTable);
        $this->assertStringContainsString('[&_table]:min-w-[42rem]', $dataTable);
        $this->assertStringContainsString('h-64 sm:h-96', $monitoringAnalytics);
        $this->assertStringContainsString('grid-cols-[1.25rem_minmax(0,1fr)]', $monitoringAnalytics);
        $this->assertStringContainsString('row-span-3 flex justify-center pt-0.5', $monitoringAnalytics);
        $this->assertStringContainsString('col-start-2 mt-0 grid min-w-0 grid-cols-2', $monitoringAnalytics);
        $this->assertStringContainsString('px-2.5 py-0.5 text-[0.5625rem] leading-none', $monitoringAnalytics);
        $this->assertStringContainsString('sm:-mx-6 sm:-mb-6 sm:px-6', $monitoringForm);
    }

    public function test_shared_button_variants_define_a_clear_action_hierarchy(): void
    {
        $button = file_get_contents(base_path('frontend/src/lib/components/Button.svelte'));
        $adminCrudTable = file_get_contents(base_path('frontend/src/lib/components/AdminCrudTable.svelte'));
        $adminApiPage = file_get_contents(base_path('frontend/src/routes/(app)/admin/api/+page.svelte'));

        $this->assertIsString($button);
        $this->assertIsString($adminCrudTable);
        $this->assertIsString($adminApiPage);
        $this->assertStringContainsString('primary: "border-wg-accent bg-wg-accent', $button);
        $this->assertStringContainsString('secondary: "border-wg-accent bg-transparent text-wg-accent', $button);
        $this->assertStringContainsString('focus-visible:outline-wg-focus', $button);
        $this->assertStringContainsString('<Button type="submit">Apply</Button>', $adminCrudTable);
        $this->assertStringContainsString('<Button type="submit">Apply</Button>', $adminApiPage);
    }

    public function test_uptime_calendar_exposes_daily_percentages_on_hover_and_keyboard_focus(): void
    {
        $monitoringAnalytics = file_get_contents(base_path('frontend/src/lib/components/MonitoringAnalytics.svelte'));

        $this->assertIsString($monitoringAnalytics);
        $this->assertStringContainsString('function uptimePercentage', $monitoringAnalytics);
        $this->assertStringContainsString('role="tooltip"', $monitoringAnalytics);
        $this->assertStringContainsString('group-hover:opacity-100', $monitoringAnalytics);
        $this->assertStringContainsString('group-focus-visible:opacity-100', $monitoringAnalytics);
        $this->assertStringContainsString('focus-visible:outline-wg-focus', $monitoringAnalytics);
        $this->assertStringContainsString('<span>Uptime</span>', $monitoringAnalytics);
        $this->assertStringContainsString('return formatDateTime(value, "—",', $monitoringAnalytics);
        $this->assertStringNotContainsString('${value}T12:00:00', $monitoringAnalytics);
        $this->assertStringContainsString('loadPreviousCalendarMonth', $monitoringAnalytics);
        $this->assertStringContainsString('Load previous month', $monitoringAnalytics);
        $this->assertStringContainsString('oldest_available_month', $monitoringAnalytics);
    }

    public function test_preference_menus_close_each_other_from_the_details_open_state(): void
    {
        $appearanceSelector = file_get_contents(base_path('frontend/src/lib/components/AppearanceSelector.svelte'));
        $localeSelector = file_get_contents(base_path('frontend/src/lib/components/LocaleSelector.svelte'));
        $monitoringDetail = file_get_contents(base_path('frontend/src/routes/(app)/monitorings/[id]/+page.svelte'));
        $localize = file_get_contents(base_path('frontend/src/lib/i18n/localize.ts'));

        $this->assertIsString($appearanceSelector);
        $this->assertIsString($localeSelector);
        $this->assertIsString($monitoringDetail);
        $this->assertIsString($localize);
        $this->assertStringContainsString('function handleToggle(event: Event): void', $appearanceSelector);
        $this->assertStringContainsString('function handleToggle(event: Event): void', $localeSelector);
        $this->assertStringContainsString('(event.currentTarget as HTMLDetailsElement).open', $appearanceSelector);
        $this->assertStringContainsString('(event.currentTarget as HTMLDetailsElement).open', $localeSelector);
        $this->assertStringContainsString('function translateDynamic(value: string, messages: TranslationMessages): string | undefined', $localize);
        $this->assertStringContainsString('Checked every (\\d+) (minutes|seconds)', $localize);
        $this->assertStringContainsString('The first monitoring results can take up to (\\d+) minutes', $localize);
        $this->assertStringContainsString('(\\d+) monitorings · (\\d+) healthy(?: · (\\d+) down)?', $localize);
        $this->assertStringContainsString('(\\d+) components · (\\d+) monitorings', $localize);
        $this->assertStringContainsString('(\\d+)–(\\d+) of (\\d+) incidents', $localize);
        $this->assertStringContainsString('function intervalLabel', $monitoringDetail);
        $this->assertStringContainsString('function periodDescription', $monitoringDetail);
    }

    public function test_public_status_page_exposes_preferences_and_uptime_tooltips(): void
    {
        $statusPage = file_get_contents(base_path('frontend/src/routes/status/[id]/+page.svelte'));
        $appearanceSelector = file_get_contents(base_path('frontend/src/lib/components/AppearanceSelector.svelte'));
        $localeSelector = file_get_contents(base_path('frontend/src/lib/components/LocaleSelector.svelte'));

        $this->assertIsString($statusPage);
        $this->assertIsString($appearanceSelector);
        $this->assertIsString($localeSelector);
        $this->assertStringContainsString('<AppearanceSelector endpoint={null} menuAlign="right" menuPlacement="below" variant="surface" />', $statusPage);
        $this->assertStringContainsString('<LocaleSelector initialLocale={data.locale} endpoint={null} menuAlign="right" menuPlacement="below" variant="surface" />', $statusPage);
        $this->assertStringContainsString('group-hover:visible group-hover:opacity-100', $statusPage);
        $this->assertStringContainsString('group-focus-visible:visible group-focus-visible:opacity-100', $statusPage);
        $this->assertStringContainsString('role="tooltip"', $statusPage);
        $this->assertStringContainsString('return formatDateTime(value, "Not recorded",', $statusPage);
        $this->assertStringNotContainsString('${value}T12:00:00', $statusPage);
        $this->assertStringContainsString('document.cookie = `webguard_locale=', $localeSelector);
        $this->assertStringContainsString('if (endpoint === null)', $appearanceSelector);
        $this->assertStringContainsString('src="/brand/webguard-logo.png"', $statusPage);
        $this->assertStringContainsString('<span class="font-extrabold tracking-tight">WebGuard</span>', $statusPage);
        $this->assertStringContainsString('import { translate, type TranslationMessages } from "$lib/i18n/localize";', $statusPage);
        $this->assertStringContainsString('return translate(value, data.locale, data.messages);', $statusPage);
    }

    public function test_login_page_exposes_guest_preferences_and_marketing_legal_links(): void
    {
        $loginPage = file_get_contents(base_path('frontend/src/routes/login/+page.svelte'));
        $authWorkspace = file_get_contents(base_path('frontend/src/lib/components/AuthWorkspace.svelte'));
        $guestLayout = file_get_contents(base_path('frontend/src/lib/components/GuestAuthLayout.svelte'));

        $this->assertIsString($loginPage);
        $this->assertIsString($authWorkspace);
        $this->assertIsString($guestLayout);
        $this->assertStringContainsString('showPreferences showLegalLinks', $loginPage);
        $this->assertStringContainsString('initialLocale={data.locale}', $loginPage);
        $this->assertStringContainsString('options.imprint_url', $authWorkspace);
        $this->assertStringContainsString('options.privacy_url', $authWorkspace);
        $this->assertStringContainsString('<AppearanceSelector endpoint={null}', $guestLayout);
        $this->assertStringContainsString('<LocaleSelector initialLocale={initialLocale} endpoint={null}', $guestLayout);
    }

    public function test_notification_inbox_updates_read_state_from_successful_mutation_responses(): void
    {
        $notificationInbox = file_get_contents(base_path('frontend/src/routes/(app)/notifications/+page.svelte'));

        $this->assertIsString($notificationInbox);
        $this->assertStringContainsString('read_notification_ids', $notificationInbox);
        $this->assertStringContainsString('entries.filter', $notificationInbox);
        $this->assertStringContainsString('entries.map((entry) => ({ ...entry, read: true }))', $notificationInbox);
        $this->assertStringContainsString('const unreadCount = payload.meta?.unread_count ?? meta.unread_count;', $notificationInbox);
        $this->assertStringContainsString('ssl_expiring: "SSL Expiring"', $notificationInbox);
        $this->assertStringContainsString('function hasTechnicalMessage', $notificationInbox);
        $this->assertStringContainsString('{#if !hasTechnicalMessage(entry)}', $notificationInbox);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $notificationInbox);

        $appShell = file_get_contents(base_path('frontend/src/lib/components/AppShell.svelte'));

        $this->assertIsString($appShell);
        $this->assertStringContainsString('unreadNotificationCount = $state(0)', $appShell);
        $this->assertStringContainsString('/api/notifications?limit=1&show_read=0', $appShell);
        $this->assertStringContainsString('bg-red-500', $appShell);
        $this->assertStringContainsString('webguard:notifications-count', $appShell);
        $this->assertStringContainsString('publishUnreadNotificationCount', $notificationInbox);
    }

    public function test_response_time_chart_uses_the_computed_application_font_stack(): void
    {
        $monitoringAnalytics = file_get_contents(base_path('frontend/src/lib/components/MonitoringAnalytics.svelte'));

        $this->assertIsString($monitoringAnalytics);
        $this->assertStringContainsString('function chartFontFamily', $monitoringAnalytics);
        $this->assertStringContainsString('getComputedStyle(document.body).fontFamily', $monitoringAnalytics);
        $this->assertStringContainsString('const fontFamily = chartFontFamily();', $monitoringAnalytics);
        $this->assertStringContainsString('family: fontFamily', $monitoringAnalytics);
        $this->assertStringNotContainsString('family: "Sen"', $monitoringAnalytics);
    }

    public function test_ci_runs_sveltekit_checks_budgets_and_container_smoke_test(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('bun run frontend:check', $workflow);
        $this->assertStringContainsString('bun run frontend:build', $workflow);
        $this->assertStringContainsString('bun run --cwd frontend budget', $workflow);
        $this->assertStringContainsString('Run SvelteKit container topology smoke test', $workflow);
        $this->assertStringContainsString('.github/scripts/smoke-sveltekit-topology.sh', $workflow);
    }

    public function test_topology_smoke_test_isolated_runtime_services_and_checks_all_health_boundaries(): void
    {
        $script = file_get_contents(base_path('.github/scripts/smoke-sveltekit-topology.sh'));

        $this->assertIsString($script);
        $this->assertStringContainsString('--profile internal-services', $script);
        $this->assertStringContainsString('docker network create', $script);
        $this->assertStringContainsString('build php frontend gateway queue-default', $script);
        $this->assertStringContainsString('up --no-build --detach --wait', $script);
        $this->assertStringContainsString('php frontend gateway schedule queue-default mysql redis', $script);
        $this->assertStringContainsString('/_health/gateway', $script);
        $this->assertStringContainsString('/_health/frontend', $script);
        $this->assertStringContainsString('/_health/laravel', $script);
        $this->assertStringContainsString('healthcheck-queue', $script);
        $this->assertStringContainsString('php artisan schedule:list', $script);
        $this->assertStringContainsString('db:seed --class=PackageSeeder', $script);
        $this->assertStringContainsString('sveltekit-browser-smoke@example.test', $script);
        $this->assertStringContainsString('SMOKE_STATUS_PAGE_SLUG', $script);
        $this->assertStringContainsString('SMOKE_UNSUBSCRIBE_TOKEN', $script);
        $this->assertStringContainsString('SMOKE_CONFIRMATION_TOKEN', $script);
        $this->assertStringContainsString('mcr.microsoft.com/playwright:v1.62.1-noble', $script);
        $this->assertStringContainsString('node_modules:/ms-playwright/node_modules:ro', $script);
        $this->assertStringContainsString('/ms-playwright/smoke/smoke-public-status.mjs', $script);
        $this->assertStringContainsString('smoke-public-status.mjs', $script);
        $this->assertStringContainsString('down --volumes --remove-orphans', $script);
    }

    public function test_browser_smoke_test_checks_the_public_status_page_at_desktop_and_mobile_viewports(): void
    {
        $browserSmokeTest = file_get_contents(base_path('frontend/scripts/smoke-public-status.mjs'));

        $this->assertIsString($browserSmokeTest);
        $this->assertStringContainsString('chromium.launch', $browserSmokeTest);
        $this->assertStringContainsString('width: 1280', $browserSmokeTest);
        $this->assertStringContainsString('width: 390', $browserSmokeTest);
        $this->assertStringContainsString('getByRole("heading"', $browserSmokeTest);
        $this->assertStringContainsString('getByLabel("Email address")', $browserSmokeTest);
        $this->assertStringContainsString('Unsubscribe from updates', $browserSmokeTest);
        $this->assertStringContainsString('subscription=unsubscribed', $browserSmokeTest);
        $this->assertStringContainsString('javaScriptEnabled: false', $browserSmokeTest);
        $this->assertStringContainsString('sveltekit-browser-subscription@example.test', $browserSmokeTest);
        $this->assertStringContainsString('Check your inbox to confirm your subscription.', $browserSmokeTest);
        $this->assertStringContainsString('consoleErrors', $browserSmokeTest);
        $this->assertStringContainsString('noHorizontalOverflow', $browserSmokeTest);
        $this->assertStringContainsString('1000 ms rendering budget', $browserSmokeTest);
    }

    public function test_gateway_and_sveltekit_preserve_safe_request_correlation(): void
    {
        $gatewayConfiguration = file_get_contents(base_path('docker/gateway/nginx.conf'));
        $proxyHeaders = file_get_contents(base_path('docker/gateway/proxy-headers.conf'));
        $svelteHooks = file_get_contents(base_path('frontend/src/hooks.server.ts'));
        $applicationBootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertIsString($gatewayConfiguration);
        $this->assertIsString($proxyHeaders);
        $this->assertIsString($svelteHooks);
        $this->assertIsString($applicationBootstrap);
        $this->assertStringContainsString('request_id=$request_id', $gatewayConfiguration);
        $this->assertStringContainsString('X-Request-Id $request_id', $proxyHeaders);
        $this->assertStringContainsString('request.headers.set("X-Request-Id", requestId)', $svelteHooks);
        $this->assertStringContainsString('event.request.headers.get("x-forwarded-for")', $svelteHooks);
        $this->assertStringContainsString('request.headers.set("X-Forwarded-For", forwardedFor)', $svelteHooks);
        $this->assertStringContainsString('$middleware->trustProxies', $applicationBootstrap);
        $this->assertStringContainsString("'172.16.0.0/12'", $applicationBootstrap);
    }

    public function test_gateway_preserves_the_https_scheme_from_coolify(): void
    {
        $gatewayConfiguration = file_get_contents(base_path('docker/gateway/nginx.conf'));
        $proxyHeaders = file_get_contents(base_path('docker/gateway/proxy-headers.conf'));

        $this->assertIsString($gatewayConfiguration);
        $this->assertIsString($proxyHeaders);
        $this->assertStringContainsString('map $http_x_forwarded_proto $forwarded_proto', $gatewayConfiguration);
        $this->assertStringContainsString('https https;', $gatewayConfiguration);
        $this->assertStringContainsString('X-Forwarded-Proto $forwarded_proto;', $proxyHeaders);
        $this->assertStringNotContainsString('X-Forwarded-Proto $scheme;', $proxyHeaders);
    }

    public function test_gateway_serves_canonical_unsubscribe_pages_from_sveltekit(): void
    {
        $gatewayConfiguration = file_get_contents(base_path('docker/gateway/nginx.conf'));
        $unsubscribePage = base_path('frontend/src/routes/status/[id]/subscribers/unsubscribe/[token]/+page.svelte');
        $unsubscribeAction = base_path('frontend/src/routes/status/[id]/subscribers/unsubscribe/[token]/+page.server.ts');

        $this->assertIsString($gatewayConfiguration);
        $this->assertStringContainsString('location ~* "^/status/[0-9a-hjkmnp-tv-z]{26}/subscribers/unsubscribe/', $gatewayConfiguration);
        $this->assertStringContainsString('^/status/[0-9a-hjkmnp-tv-z]{26}/subscribers/unsubscribe/', $gatewayConfiguration);
        $this->assertStringContainsString('proxy_pass http://sveltekit', $gatewayConfiguration);
        $this->assertFileExists($unsubscribePage);
        $this->assertFileExists($unsubscribeAction);
        $this->assertStringContainsString('subscribers/unsubscribe', (string) file_get_contents($unsubscribeAction));
    }

    public function test_gateway_serves_canonical_subscription_confirmations_from_sveltekit(): void
    {
        $gatewayConfiguration = file_get_contents(base_path('docker/gateway/nginx.conf'));
        $confirmationPage = base_path('frontend/src/routes/status/[id]/subscribers/confirm/[token]/+page.svelte');
        $confirmationAction = base_path('frontend/src/routes/status/[id]/subscribers/confirm/[token]/+page.server.ts');

        $this->assertIsString($gatewayConfiguration);
        $this->assertStringContainsString('location ~* "^/status/[0-9a-hjkmnp-tv-z]{26}/subscribers/confirm/', $gatewayConfiguration);
        $this->assertStringContainsString('if ($request_method !~ "^GET$")', $gatewayConfiguration);
        $this->assertStringContainsString('proxy_pass http://sveltekit', $gatewayConfiguration);
        $this->assertFileExists($confirmationPage);
        $this->assertFileExists($confirmationAction);
        $this->assertStringContainsString('subscribers/confirm', (string) file_get_contents($confirmationAction));
        $this->assertStringContainsString('subscription=confirmed', (string) file_get_contents($confirmationAction));
    }

    public function test_gateway_forwards_canonical_status_page_subscription_posts_to_sveltekit(): void
    {
        $gatewayConfiguration = file_get_contents(base_path('docker/gateway/nginx.conf'));
        $statusPageAction = base_path('frontend/src/routes/status/[id]/+page.server.ts');

        $this->assertIsString($gatewayConfiguration);
        $this->assertStringContainsString('$public_status_cache_control', $gatewayConfiguration);
        $this->assertStringContainsString('^GET$|^HEAD$|^POST$', $gatewayConfiguration);
        $this->assertStringContainsString('proxy_pass http://sveltekit', $gatewayConfiguration);
        $this->assertFileExists($statusPageAction);
        $this->assertStringContainsString('export const actions', (string) file_get_contents($statusPageAction));
        $this->assertStringContainsString('/api/public/status/', (string) file_get_contents($statusPageAction));
        $this->assertStringContainsString('redirect(301', (string) file_get_contents($statusPageAction));
        $this->assertStringContainsString('redirect(307', (string) file_get_contents($statusPageAction));
    }

    public function test_topology_smoke_uses_the_log_mailer_without_changing_the_default_transport(): void
    {
        $smokeScript = file_get_contents(base_path('.github/scripts/smoke-sveltekit-topology.sh'));
        $composeConfiguration = file_get_contents(base_path('docker-compose.yml'));
        $installationGuide = file_get_contents(base_path('docs/installation.md'));

        $this->assertIsString($smokeScript);
        $this->assertIsString($composeConfiguration);
        $this->assertIsString($installationGuide);
        $this->assertStringContainsString('export MAIL_MAILER="${MAIL_MAILER:-log}"', $smokeScript);
        $this->assertStringContainsString('MAIL_MAILER: "${MAIL_MAILER:-smtp}"', $composeConfiguration);
        $this->assertStringContainsString('export APP_URL="${APP_URL:-http://gateway:8080}"', $smokeScript);
        $this->assertStringContainsString('APP_URL: "${APP_URL:?APP_URL must be set to the canonical application URL}"', $composeConfiguration);
        $this->assertStringContainsString('ORIGIN: "${APP_URL:?APP_URL must be set to the canonical application URL}"', $composeConfiguration);
        $this->assertStringNotContainsString('SERVICE_URL_GATEWAY', $composeConfiguration);
        $this->assertStringNotContainsString('SERVICE_FQDN_GATEWAY', $composeConfiguration);
        $this->assertStringNotContainsString('SERVICE_URL_PHP', $composeConfiguration);
        $this->assertStringContainsString('Do not set a `SERVICE_FQDN_GATEWAY*` variable to `${APP_URL}`.', $installationGuide);
        $this->assertStringContainsString('set **only** the gateway domain to `https://app.webguard.dev:8080`', $installationGuide);
    }

    public function test_only_the_gateway_declares_a_publicly_routable_compose_port(): void
    {
        $composeConfiguration = file_get_contents(base_path('docker-compose.yml'));

        $this->assertIsString($composeConfiguration);

        preg_match('/^  php:\R(?<service>.*?)(?=^  frontend:)/ms', $composeConfiguration, $phpMatches);
        preg_match('/^  frontend:\R(?<service>.*?)(?=^  gateway:)/ms', $composeConfiguration, $frontendMatches);

        $this->assertArrayHasKey('service', $phpMatches);
        $this->assertArrayHasKey('service', $frontendMatches);

        $this->assertStringNotContainsString("\n    expose:", $phpMatches['service']);
        $this->assertStringNotContainsString("\n    expose:", $frontendMatches['service']);
        $this->assertStringContainsString("\n    expose:\n      - \"8080\"", $composeConfiguration);
    }
}
