<script lang="ts">
    import { invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import Button from "#lib/components/Button.svelte";
    import type { FirstPartySession } from "#lib/api/models.ts";

    interface Props {
        onboarding: FirstPartySession["user"]["onboarding"];
        hasActiveMonitoring: boolean;
        firstMonitoringId: string | null;
        onAddWebsite: () => void | Promise<void>;
    }

    let { onboarding, hasActiveMonitoring, firstMonitoringId, onAddWebsite }: Props = $props();
    let saving = $state(false);
    let error = $state("");

    const notificationTested = $derived(onboarding.notification_tested_at !== null);
    const canComplete = $derived(hasActiveMonitoring && notificationTested);

    async function save(action: "complete" | "dismiss"): Promise<void> {
        if (saving) return;

        saving = true;
        error = "";

        try {
            await requestFirstPartyApi("/api/onboarding", {
                method: "PATCH",
                body: JSON.stringify({ action }),
            });
            await invalidateAll();
        } catch (exception) {
            error = exception instanceof FirstPartyApiError
                ? exception.message
                : "Setup could not be saved. Please try again.";
        } finally {
            saving = false;
        }
    }
</script>

<section class="mb-6 rounded-2xl border border-violet-200 bg-violet-50 p-5 shadow-wg-surface dark:border-violet-800 dark:bg-violet-950/40 sm:p-6" aria-labelledby="first-run-onboarding-title">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="m-0 text-xs font-extrabold tracking-[0.12em] text-wg-accent uppercase">Getting started</p>
            <h2 id="first-run-onboarding-title" class="mt-2 text-xl font-bold">Set up WebGuard in two quick steps</h2>
            <p class="mt-2 text-sm leading-6 text-wg-text-muted">Create one website check, then confirm that alerts can reach you.</p>
        </div>
        <Button variant="quiet" type="button" disabled={saving} aria-label="Dismiss setup guide" onclick={() => save("dismiss")}>Dismiss</Button>
    </header>

    <ol class="mt-5 grid list-none gap-3 p-0 md:grid-cols-2">
        <li class="rounded-xl border border-wg-border bg-wg-surface p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="m-0 text-sm font-bold">1. Add your first website</h3>
                    <p class="mb-0 mt-2 text-sm leading-5 text-wg-text-muted">Start with the standard availability check.</p>
                </div>
                {#if hasActiveMonitoring}
                    <span class="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Completed</span>
                {/if}
            </div>
            {#if !hasActiveMonitoring}
                <Button class="mt-4" type="button" onclick={onAddWebsite}>Add your website</Button>
            {:else if firstMonitoringId}
                <a class="mt-4 inline-flex rounded-sm text-sm font-bold text-wg-accent underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus" href={`/monitorings/${firstMonitoringId}`}>View your first check</a>
                <p class="mb-0 mt-2 text-sm leading-5 text-wg-text-muted">The details page shows when the first result is expected.</p>
            {/if}
        </li>

        <li class="rounded-xl border border-wg-border bg-wg-surface p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="m-0 text-sm font-bold">2. Set up and test alerts</h3>
                    <p class="mb-0 mt-2 text-sm leading-5 text-wg-text-muted">Choose a channel and send a test notification.</p>
                </div>
                {#if notificationTested}
                    <span class="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Completed</span>
                {/if}
            </div>
            {#if notificationTested}
                <p class="mb-0 mt-4 text-sm font-semibold text-emerald-700 dark:text-emerald-300">Your test notification was delivered successfully.</p>
            {:else}
                <a class="mt-4 inline-flex rounded-sm text-sm font-bold text-wg-accent underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus" href="/profile/notifications">Open notification settings</a>
            {/if}
        </li>
    </ol>

    {#if canComplete}
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-violet-200 pt-4 dark:border-violet-800">
            <p class="m-0 text-sm font-semibold">Your website check is active and the test alert was delivered.</p>
            <Button type="button" loading={saving} onclick={() => save("complete")}>Complete setup</Button>
        </div>
    {/if}

    {#if error}<p class="mb-0 mt-4 text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}
</section>
