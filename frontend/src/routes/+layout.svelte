<script lang="ts">
    import "../app.css";
    import { localize } from "#lib/i18n/localize.ts";
    import type { TranslationMessages } from "#lib/i18n/localize.ts";
    import CookieConsent from "#lib/components/CookieConsent.svelte";

    interface Props {
        data: {
            locale: string;
            messages: TranslationMessages;
            analytics: { enabled: boolean; gtmContainerId: string | null; ga4MeasurementId: string | null };
        };
        children: import("svelte").Snippet;
    }

    let { data, children }: Props = $props();
</script>

<svelte:head>
    <link rel="icon" type="image/png" sizes="32x32" href="/brand/favicon-32.png" />
    <link rel="icon" type="image/png" sizes="192x192" href="/brand/icon-192.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="/brand/apple-touch-icon.png" />
    <link rel="manifest" href="/manifest.webmanifest" />
    <meta name="theme-color" content="#6b21a8" />
</svelte:head>

<div use:localize={{ locale: data.locale, messages: data.messages }}>
    {@render children()}
    {#if data.analytics.enabled}
        <CookieConsent
            gtmContainerId={data.analytics.gtmContainerId}
            ga4MeasurementId={data.analytics.ga4MeasurementId}
        />
    {/if}
</div>
