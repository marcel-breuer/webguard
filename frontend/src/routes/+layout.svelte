<script lang="ts">
    import "../app.css";
    import { onMount, tick } from "svelte";
    import { localize } from "#lib/i18n/localize.ts";
    import type { TranslationMessages } from "#lib/i18n/localize.ts";

    interface Props {
        data: {
            locale: string;
            messages: TranslationMessages;
        };
        children: import("svelte").Snippet;
    }

    let { data, children }: Props = $props();

    // Signals that client-side hydration has settled, so browser tests do not type into server-rendered markup that is about to be replaced.
    onMount(async () => {
        await tick();
        document.documentElement.dataset.hydrated = "true";
    });
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
</div>
