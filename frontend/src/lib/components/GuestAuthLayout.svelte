<script lang="ts">
    import type { Snippet } from "svelte";
    import AppearanceSelector from "$lib/components/AppearanceSelector.svelte";
    import LocaleSelector from "$lib/components/LocaleSelector.svelte";

    interface Props {
        title: string;
        description: string;
        initialLocale?: string;
        showPreferences?: boolean;
        children?: Snippet;
    }

    let { title, description, initialLocale = "en", showPreferences = false, children }: Props = $props();
</script>

<main class="mx-auto flex min-h-screen w-[min(36rem,calc(100%_-_2rem))] items-center py-8 sm:py-12">
    <section class="w-full rounded-3xl border border-wg-border bg-wg-surface p-6 shadow-wg-surface sm:p-10">
        <div class="flex items-center justify-between gap-4">
            <a class="inline-flex items-center gap-3 text-wg-text no-underline" href="/login" aria-label="WebGuard login">
                <img class="size-10 rounded-xl bg-white object-contain p-0.5" src="/brand/webguard-logo.png" alt="" />
                <span class="font-extrabold tracking-tight">WebGuard</span>
            </a>
            {#if showPreferences}
                <div class="flex shrink-0 gap-2" aria-label="Page preferences">
                    <AppearanceSelector endpoint={null} menuAlign="right" menuPlacement="below" variant="surface" />
                    <LocaleSelector initialLocale={initialLocale} endpoint={null} menuAlign="right" menuPlacement="below" variant="surface" />
                </div>
            {/if}
        </div>
        <header class="mt-8">
            <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">{title}</h1>
            <p class="mt-3 leading-6 text-wg-text-muted">{description}</p>
        </header>
        <div class="mt-8">{@render children?.()}</div>
    </section>
</main>
