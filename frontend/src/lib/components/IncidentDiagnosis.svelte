<script lang="ts">
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import type { IncidentDiagnosis, IncidentDiagnosisPayload } from "#lib/api/monitoring.ts";
    import { formatDateTime } from "#lib/i18n/format.ts";
    import Button from "#lib/components/Button.svelte";
    import StatusBadge from "#lib/components/StatusBadge.svelte";

    interface Props {
        incidentId: string;
        diagnosis: IncidentDiagnosis | null;
    }

    let { incidentId, diagnosis: initialDiagnosis }: Props = $props();
    let diagnosis = $state<IncidentDiagnosis | null>(null);
    let recomputing = $state(false);
    let error = $state("");

    $effect(() => {
        diagnosis = initialDiagnosis;
    });

    const primary = $derived(diagnosis?.primary ?? null);
    const others = $derived(diagnosis?.hypotheses.filter((hypothesis) => hypothesis.rank !== primary?.rank) ?? []);

    function tone(level: "high" | "medium" | "low"): "danger" | "degraded" | "neutral" {
        return level === "high" ? "danger" : level === "medium" ? "degraded" : "neutral";
    }

    async function recompute(): Promise<void> {
        if (recomputing) return;

        recomputing = true;
        error = "";

        try {
            const payload = await requestFirstPartyApi<IncidentDiagnosisPayload>(
                `/api/incidents/${encodeURIComponent(incidentId)}/diagnosis`,
                { method: "POST" },
            );
            diagnosis = payload.data.diagnosis;
        } catch (caught) {
            error = caught instanceof FirstPartyApiError ? caught.message : "The diagnosis could not be updated.";
        } finally {
            recomputing = false;
        }
    }
</script>

<div class="mt-4 rounded-xl border border-wg-border bg-wg-surface-muted/40 p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="font-bold">Probable cause</h3>
        <Button variant="quiet" class="min-h-9 px-3 py-1.5" loading={recomputing} onclick={recompute}>Recompute diagnosis</Button>
    </div>
    {#if primary}
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <span class="font-semibold">{primary.label}</span>
            <StatusBadge tone={tone(primary.confidence_level)} label={primary.confidence_label} />
        </div>
        <p class="mt-2 text-sm leading-6">{primary.summary}</p>
        <p class="mt-3 text-sm font-semibold">Supporting evidence</p>
        <ul class="mt-1 grid gap-1 pl-5 text-sm text-wg-text-muted">
            {#each primary.evidence as item}
                <li>{item.message}</li>
            {/each}
        </ul>
        {#if others.length > 0}
            <details class="mt-4 text-sm">
                <summary class="cursor-pointer font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus">Other hypotheses</summary>
                <ol class="mt-2 grid gap-3 p-0">
                    {#each others as hypothesis (hypothesis.rank)}
                        <li class="list-none">
                            <p><span class="font-semibold">{hypothesis.label}</span> <span class="text-wg-text-muted">· {hypothesis.confidence_label}</span></p>
                            <p class="mt-1 leading-6 text-wg-text-muted">{hypothesis.summary}</p>
                        </li>
                    {/each}
                </ol>
            </details>
        {/if}
        <p class="mt-3 text-xs text-wg-text-muted">{formatDateTime(diagnosis?.generated_at ?? null, "—")}</p>
    {:else}
        <p class="mt-2 text-sm leading-6 text-wg-text-muted">No diagnosis is available for this incident yet.</p>
    {/if}
    {#if error}<p class="mt-3 text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}
</div>
