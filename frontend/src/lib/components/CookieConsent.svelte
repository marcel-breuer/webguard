<script lang="ts">
    import { afterNavigate } from "$app/navigation";
    import { onMount } from "svelte";

    interface Props {
        gtmContainerId: string | null;
        ga4MeasurementId: string | null;
    }

    interface ConsentRecord {
        statistics: boolean;
        updatedAt: string;
    }

    let { gtmContainerId, ga4MeasurementId }: Props = $props();
    let consent: ConsentRecord | null = $state(null);
    let visible = $state(false);
    let configuring = $state(false);
    let statistics = $state(false);
    let trackingReady = false;
    let trackingInitialized = false;
    let pageViewPending = false;
    let lastNavigationPath: string | null = null;

    const consentStorageKey = "webguard_cookie_consent_v1";

    function readConsent(): ConsentRecord | null {
        try {
            const stored = localStorage.getItem(consentStorageKey);
            if (!stored) return null;

            const parsed = JSON.parse(stored) as Partial<ConsentRecord>;
            if (typeof parsed.statistics !== "boolean" || typeof parsed.updatedAt !== "string") {
                localStorage.removeItem(consentStorageKey);
                return null;
            }
            const updatedAt = Date.parse(parsed.updatedAt);
            if (!Number.isFinite(updatedAt) || Date.now() - updatedAt > 180 * 24 * 60 * 60 * 1000) {
                localStorage.removeItem(consentStorageKey);
                return null;
            }

            return { statistics: parsed.statistics, updatedAt: parsed.updatedAt };
        } catch {
            try {
                localStorage.removeItem(consentStorageKey);
            } catch {
                // Ignore storage access failures; analytics still stay disabled.
            }
            return null;
        }
    }

    function saveConsent(allowStatistics: boolean): void {
        const nextConsent = { statistics: allowStatistics, updatedAt: new Date().toISOString() };
        let stored = false;
        try {
            localStorage.setItem(consentStorageKey, JSON.stringify(nextConsent));
            stored = true;
        } catch {
            // Do not load third-party analytics when the user's choice cannot be stored.
            nextConsent.statistics = false;
        }
        consent = nextConsent;
        visible = false;
        configuring = false;

        if (allowStatistics && stored) {
            initializeAnalytics();
            sendPageView();
        } else {
            if (trackingInitialized) {
                window.gtag?.("consent", "update", {
                    analytics_storage: "denied",
                    ad_storage: "denied",
                    ad_user_data: "denied",
                    ad_personalization: "denied",
                });
            }
            trackingReady = false;
        }
    }

    function isExcludedPath(pathname: string): boolean {
        return pathname === "/status" || pathname.startsWith("/status/");
    }

    function pageCategory(pathname: string): string | null {
        if (isExcludedPath(pathname)) return null;
        const routes: Array<[RegExp, string]> = [
            [/^\/dashboard\/?$/, "dashboard"],
            [/^\/monitorings(?:\/.*)?$/, "monitorings"],
            [/^\/incidents(?:\/.*)?$/, "incidents"],
            [/^\/teams(?:\/.*)?$/, "teams"],
            [/^\/maintenance(?:\/.*)?$/, "maintenance"],
            [/^\/notifications(?:\/.*)?$/, "notifications"],
            [/^\/profile(?:\/.*)?$/, "profile"],
            [/^\/admin(?:\/.*)?$/, "admin"],
            [/^\/(?:login|register|forgot-password|reset-password|verify-email)(?:\/.*)?$/, "authentication"],
        ];

        return routes.find(([pattern]) => pattern.test(pathname))?.[1] ?? null;
    }

    function initializeAnalytics(): void {
        if (!consent?.statistics || isExcludedPath(window.location.pathname) || (!gtmContainerId && !ga4MeasurementId)) return;
        if (trackingInitialized) {
            window.gtag?.("consent", "update", {
                analytics_storage: "granted",
                ad_storage: "denied",
                ad_user_data: "denied",
                ad_personalization: "denied",
            });
            trackingReady = true;
            pageViewPending = true;
            return;
        }

        window.dataLayer = window.dataLayer ?? [];
        window.gtag = function gtag() {
            window.dataLayer?.push(Array.from(arguments));
        };
        window.gtag("consent", "default", {
            analytics_storage: "denied",
            ad_storage: "denied",
            ad_user_data: "denied",
            ad_personalization: "denied",
        });
        window.gtag("consent", "update", {
            analytics_storage: "granted",
            ad_storage: "denied",
            ad_user_data: "denied",
            ad_personalization: "denied",
        });

        const script = document.createElement("script");
        script.async = true;
        script.src = gtmContainerId
            ? `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(gtmContainerId)}`
            : `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(ga4MeasurementId ?? "")}`;
        document.head.appendChild(script);

        if (!gtmContainerId && ga4MeasurementId) {
            window.gtag("js", new Date());
            window.gtag("config", ga4MeasurementId, { send_page_view: false });
        }

        trackingReady = true;
        trackingInitialized = true;
        pageViewPending = true;
    }

    function sendPageView(): void {
        if (!consent?.statistics || isExcludedPath(window.location.pathname)) return;
        if (!trackingInitialized) initializeAnalytics();
        if (!trackingReady) return;
        if (!pageViewPending) return;

        const category = pageCategory(window.location.pathname);
        pageViewPending = false;
        if (!category) return;

        if (gtmContainerId) {
            window.dataLayer?.push({ event: "webguard_page_view", page_category: category });
        } else if (ga4MeasurementId) {
            window.gtag?.("event", "page_view", {
                page_path: `/app/${category}`,
                page_location: `${window.location.origin}/app/${category}`,
                page_title: `WebGuard ${category}`,
            });
        }
    }

    function openSettings(): void {
        statistics = consent?.statistics ?? false;
        configuring = true;
        visible = true;
    }

    function closeSettings(): void {
        configuring = false;
        visible = consent === null;
    }

    onMount(() => {
        consent = readConsent();
        statistics = consent?.statistics ?? false;
        visible = consent === null;
        lastNavigationPath = window.location.pathname;
        if (consent?.statistics) {
            initializeAnalytics();
            sendPageView();
        }
    });

    afterNavigate(({ to }) => {
        const pathname = to?.url.pathname ?? window.location.pathname;
        if (pathname === lastNavigationPath) return;

        lastNavigationPath = pathname;
        pageViewPending = true;
        sendPageView();
    });
</script>

{#if visible}
    <section class="fixed inset-x-3 bottom-3 z-[100] mx-auto max-w-3xl rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-2xl sm:inset-x-6 sm:bottom-6 sm:p-6" aria-label="Cookie preferences">
        <h2 class="text-lg font-bold text-wg-text">Cookie preferences</h2>
        <p class="mt-2 text-sm leading-6 text-wg-text-muted">
            WebGuard stores your preference on this device. If you allow statistics, limited page categories may be sent to Google Analytics. Google services are not contacted before you opt in. Public status pages are excluded.
        </p>
        <div class="mt-4 rounded-xl border border-wg-border p-4 text-sm">
            <p class="font-semibold text-wg-text">Necessary storage</p>
            <p class="mt-1 text-wg-text-muted">Stores this choice on your device. It cannot be disabled here.</p>
        </div>
        {#if configuring}
            <label class="mt-4 flex items-start gap-3 rounded-xl border border-wg-border p-4 text-sm text-wg-text">
                <input bind:checked={statistics} type="checkbox" class="mt-1 size-4 accent-wg-accent" />
                <span><span class="block font-semibold">Statistics</span><span class="mt-1 block text-wg-text-muted">Allow page category measurements.</span></span>
            </label>
        {/if}
        <div class="mt-5 flex flex-wrap justify-end gap-2">
            {#if configuring}
                <button class="rounded-lg border border-wg-border px-4 py-2 text-sm font-semibold text-wg-text hover:bg-wg-surface-muted" onclick={closeSettings}>Cancel</button>
                <button class="rounded-lg border border-wg-border px-4 py-2 text-sm font-semibold text-wg-text hover:bg-wg-surface-muted" onclick={() => saveConsent(false)}>Reject all</button>
                <button class="rounded-lg bg-wg-accent px-4 py-2 text-sm font-semibold text-white hover:brightness-110" onclick={() => saveConsent(statistics)}>Save choices</button>
            {:else}
                <button class="rounded-lg border border-wg-border px-4 py-2 text-sm font-semibold text-wg-text hover:bg-wg-surface-muted" onclick={() => saveConsent(false)}>Reject all</button>
                <button class="rounded-lg border border-wg-border px-4 py-2 text-sm font-semibold text-wg-text hover:bg-wg-surface-muted" onclick={openSettings}>Configure</button>
                <button class="rounded-lg border border-wg-border bg-wg-surface px-4 py-2 text-sm font-semibold text-wg-text hover:bg-wg-surface-muted" onclick={() => saveConsent(true)}>Accept all</button>
            {/if}
        </div>
    </section>
{:else}
    <button class="fixed bottom-3 right-3 z-[100] rounded-full border border-wg-border bg-wg-surface px-4 py-2 text-xs font-semibold text-wg-text shadow-lg hover:bg-wg-surface-muted sm:bottom-5 sm:right-5" onclick={openSettings}>Cookie settings</button>
{/if}

<svelte:window onstorage={(event) => {
    if (event.key === consentStorageKey) {
        consent = readConsent();
        if (!consent?.statistics) {
            if (trackingInitialized) {
                window.gtag?.("consent", "update", {
                    analytics_storage: "denied",
                    ad_storage: "denied",
                    ad_user_data: "denied",
                    ad_personalization: "denied",
                });
            }
            trackingReady = false;
        } else {
            initializeAnalytics();
        }
    }
}} />

