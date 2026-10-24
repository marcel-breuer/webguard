<script lang="ts">
    import { invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "$lib/api/client";
    import { formatDateTime, formatNumber } from "$lib/i18n/format";
    import Button from "$lib/components/Button.svelte";
    import Card from "$lib/components/Card.svelte";
    import Field from "$lib/components/Field.svelte";
    import Input from "$lib/components/Input.svelte";
    import Select from "$lib/components/Select.svelte";
    import StatusBadge from "$lib/components/StatusBadge.svelte";
    import type { MaintenanceCapabilities, MaintenanceWindow } from "./+page.server";

    interface Props { data: { capabilities: MaintenanceCapabilities; oneOff: { data: MaintenanceWindow[] }; recurring: { data: MaintenanceWindow[] } }; }
    let { data }: Props = $props();
    let mode = $state<"one_off" | "recurring">("one_off");
    let scope = $state<"monitoring" | "group">("monitoring");
    let submitting = $state(false);
    let pendingWindowAction = $state<string | null>(null);
    let error = $state("");
    let message = $state("");
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    function timestamp(value: string | null): string {
        return formatDateTime(value, "Open-ended");
    }

    function tone(state: MaintenanceWindow["state"]): "healthy" | "degraded" | "danger" | "neutral" | "paused" {
        return state === "active" ? "healthy" : state === "upcoming" ? "degraded" : state === "disabled" ? "paused" : "neutral";
    }

    function stateLabel(state: MaintenanceWindow["state"]): string { return state.replaceAll("_", " "); }

    async function schedule(event: SubmitEvent): Promise<void> {
        event.preventDefault();

        if (submitting) {
            return;
        }

        submitting = true;
        error = "";
        message = "";
        try {
            const response = await requestFirstPartyApi<{ kind: string; updated_count?: number }>("/api/maintenance", {
                body: new FormData(event.currentTarget as HTMLFormElement),
                headers: { "Idempotency-Key": crypto.randomUUID() },
                method: "POST",
            });
            message = response.data.kind === "one_off" ? `Maintenance scheduled for ${formatNumber(response.data.updated_count ?? 0)} monitoring(s).` : "Recurring maintenance scheduled.";
            await invalidateAll();
        } catch (exception) {
            error = exception instanceof FirstPartyApiError ? exception.message : "Maintenance could not be scheduled.";
        } finally { submitting = false; }
    }

    async function cancel(window: MaintenanceWindow): Promise<void> {
        if (!globalThis.confirm(`Clear maintenance for ${window.target.name}?`)) return;

        if (pendingWindowAction !== null) {
            return;
        }

        pendingWindowAction = `one-off:${window.id}`;
        error = "";
        try {
            await requestFirstPartyApi(`/api/maintenance/one-off/${window.id}`, { method: "DELETE" });
            await invalidateAll();
        } catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : "Maintenance could not be cleared."; } finally { pendingWindowAction = null; }
    }

    async function toggle(window: MaintenanceWindow): Promise<void> {
        if (pendingWindowAction !== null) {
            return;
        }

        pendingWindowAction = `recurring:${window.id}`;
        error = "";
        try {
            await requestFirstPartyApi(`/api/maintenance/recurring/${window.id}`, { body: JSON.stringify({ enabled: !window.enabled }), method: "PATCH" });
            await invalidateAll();
        } catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : "Recurring maintenance could not be updated."; } finally { pendingWindowAction = null; }
    }
</script>

<svelte:head><title>Maintenance | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(70rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8"><p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Operations</p><h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Maintenance</h1><p class="mt-3 leading-6 text-wg-text-muted">Schedule planned maintenance for manageable monitorings and private groups.</p></header>
    {#if message}<p class="mb-6 text-sm font-bold text-green-700 dark:text-green-300" role="status">{message}</p>{/if}{#if error}<p class="mb-6 text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}
    <section class="grid gap-6 xl:grid-cols-[0.85fr_1.15fr]"><Card title="Schedule maintenance" description="One-off changes are protected against duplicate submissions.">{#if data.capabilities.can_schedule}<form class="grid gap-4" onsubmit={schedule} novalidate><Field label="Type"><Select name="mode" bind:value={mode}><option value="one_off">One-off</option><option value="recurring">Recurring</option></Select></Field><Field label="Scope"><Select name="scope" bind:value={scope}><option value="monitoring">Monitoring</option><option value="group">Monitoring group</option></Select></Field>{#if scope === "monitoring"}<Field label="Monitoring" required><Select name="monitoring_id" required>{#each data.capabilities.manageable_monitorings as monitoring}<option value={monitoring.id}>{monitoring.name}</option>{/each}</Select></Field>{:else}<Field label="Monitoring group" required><Select name="monitoring_group_id" required>{#each data.capabilities.monitoring_groups as group}<option value={group.id}>{group.name} ({formatNumber(group.monitorings_count)})</option>{/each}</Select></Field>{/if}{#if mode === "one_off"}<div class="grid gap-4 sm:grid-cols-2"><Field label="Starts at" required><Input name="maintenance_from" type="datetime-local" required /></Field><Field label="Ends at"><Input name="maintenance_until" type="datetime-local" /></Field></div>{:else}<div class="grid gap-4 sm:grid-cols-2"><Field label="First start" required><Input name="recurring_starts_at" type="datetime-local" required /></Field><Field label="Repeat" required><Select name="recurrence"><option value="weekly">Weekly</option><option value="monthly">Monthly</option></Select></Field><Field label="Duration (minutes)" required><Input name="recurring_duration_minutes" type="number" min="1" max="1440" value="60" required /></Field><Field label="Timezone" required><Input name="recurring_timezone" value={timezone} required /></Field></div>{/if}<Button type="submit" loading={submitting}>Schedule maintenance</Button></form>{:else}<p class="text-sm leading-6 text-wg-text-muted">You do not have manageable monitorings for maintenance scheduling.</p>{/if}</Card>
        <div class="grid gap-6"><Card title="One-off maintenance" description="Active, upcoming, and past maintenance applied to monitorings.">{#if data.oneOff.data.length > 0}<div class="grid gap-3">{#each data.oneOff.data as window (window.id)}<article class="flex flex-col justify-between gap-3 rounded-xl border border-wg-border p-4 sm:flex-row sm:items-center"><div><div class="flex items-center gap-3"><h3 class="font-bold">{window.target.name}</h3><StatusBadge tone={tone(window.state)} label={stateLabel(window.state)} /></div><p class="mt-2 text-sm text-wg-text-muted">{timestamp(window.schedule.starts_at)} · {timestamp(window.schedule.ends_at)}</p></div>{#if window.can_manage}<Button class="min-h-10 px-3 py-1.5" variant="secondary" type="button" disabled={pendingWindowAction !== null} aria-busy={pendingWindowAction === `one-off:${window.id}`} onclick={() => cancel(window)}>Clear</Button>{/if}</article>{/each}</div>{:else}<p class="text-sm leading-6 text-wg-text-muted">No one-off maintenance is scheduled.</p>{/if}</Card>
        <Card title="Recurring maintenance" description="Enable or pause recurring maintenance windows.">{#if data.recurring.data.length > 0}<div class="grid gap-3">{#each data.recurring.data as window (window.id)}<article class="flex flex-col justify-between gap-3 rounded-xl border border-wg-border p-4 sm:flex-row sm:items-center"><div><div class="flex items-center gap-3"><h3 class="font-bold">{window.target.name}</h3><StatusBadge tone={tone(window.state)} label={stateLabel(window.state)} /></div><p class="mt-2 text-sm text-wg-text-muted">{window.schedule.recurrence} · {timestamp(window.schedule.starts_at)} · {window.schedule.duration_minutes === null ? "—" : formatNumber(window.schedule.duration_minutes)} minutes</p></div>{#if window.can_manage}<Button class="min-h-10 px-3 py-1.5" variant="secondary" type="button" disabled={pendingWindowAction !== null} aria-busy={pendingWindowAction === `recurring:${window.id}`} onclick={() => toggle(window)}>{window.enabled ? "Pause" : "Enable"}</Button>{/if}</article>{/each}</div>{:else}<p class="text-sm leading-6 text-wg-text-muted">No recurring maintenance is scheduled.</p>{/if}</Card></div></section>
</main>
