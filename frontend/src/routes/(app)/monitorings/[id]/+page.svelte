<script lang="ts">
    import { goto, invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "$lib/api/client";
    import { appRoutes } from "$lib/routes";
    import { formatDateTime } from "$lib/i18n/format";
    import Button from "$lib/components/Button.svelte";
    import Dialog from "$lib/components/Dialog.svelte";
    import MonitoringAnalytics from "$lib/components/MonitoringAnalytics.svelte";
    import MonitoringForm from "$lib/components/MonitoringForm.svelte";
    import ServerHealthOverview from "$lib/components/ServerHealthOverview.svelte";
    import MonitoringTypeDiagnostics from "$lib/components/MonitoringTypeDiagnostics.svelte";
    import NavIcon from "$lib/components/NavIcon.svelte";
    import type { MonitoringDetailData, MonitoringFormOptions, MonitoringHeatmapPoint, MonitoringMutationResult, MonitoringSummary } from "$lib/api/monitoring";

    interface Props {
        data: {
            monitoring: MonitoringSummary;
            detail: MonitoringDetailData;
            incidentsMeta: { limit: number; offset: number; has_more: boolean; next_offset: number | null };
            recentChecksMeta: { limit: number; has_more: boolean; next_offset: number | null };
            uptimeCalendarMeta: { oldest_available_month: string | null };
        };
    }
    let { data }: Props = $props();
    let deleting = $state(false);
    let editForm = $state<MonitoringFormOptions | null>(null);
    let editLoading = $state(false);
    let editOpen = $state(false);
    let error = $state("");

    const linkedTarget = $derived(targetUrl(data.monitoring.target));
    const availabilityPeriods = $derived(data.detail.availability_periods);

    function statusTone(): "healthy" | "degraded" | "danger" | "neutral" | "paused" {
        if (data.monitoring.lifecycle_status === "paused") return "paused";
        if (data.detail.current_check.status === "up") return "healthy";
        if (data.detail.current_check.status === "down") return "danger";
        if (data.detail.current_check.status === "unknown") return "degraded";
        return "neutral";
    }

    function statusLabel(): string {
        if (data.monitoring.lifecycle_status === "paused") return "Paused";
        return data.detail.current_check.status === "up" ? "Up" : data.detail.current_check.status === "down" ? "Down" : "Unknown";
    }

    function timestamp(value: string | null): string {
        return formatDateTime(value, "—", {
            month: "2-digit", day: "2-digit", year: "numeric",
            hour: "2-digit", minute: "2-digit", second: "2-digit",
        });
    }

    function intervalLabel(seconds: number | null): string {
        if (seconds === null || seconds <= 0) return "Waiting for the first result";
        return seconds % 60 === 0 ? `Checked every ${seconds / 60} minutes` : `Checked every ${seconds} seconds`;
    }

    function targetUrl(target: string): string | null {
        try {
            const url = new URL(target);
            return ["http:", "https:"].includes(url.protocol) ? url.toString() : null;
        } catch {
            return null;
        }
    }

    function availability(days: "7" | "30" | "90"): string {
        const percentage = availabilityPeriods[days]?.uptime.percentage;
        return percentage === null || percentage === undefined ? "—" : `${percentage.toFixed(2)}%`;
    }

    function periodDescription(days: "7" | "30" | "90"): string {
        const period = availabilityPeriods[days];
        if (!period?.has_data) return "No availability data yet";
        const incidents = period.downtime.incidents_count;
        return `${incidents} ${incidents === 1 ? "incident" : "incidents"}, ${period.downtime.percentage?.toFixed(2) ?? "0.00"}% downtime`;
    }

    function heatmapClass(point: MonitoringHeatmapPoint): string {
        if (point.uptime === point.downtime) return "bg-wg-surface-muted";
        return point.uptime > point.downtime ? "bg-emerald-500" : "bg-red-500";
    }

    async function remove(): Promise<void> {
        if (deleting || !window.confirm(`Delete ${data.monitoring.name}? This cannot be undone.`)) return;

        deleting = true;
        error = "";

        try {
            await requestFirstPartyApi(`/api/monitorings/${data.monitoring.id}`, { method: "DELETE" });
            await goto(appRoutes.monitorings);
        } catch (exception) {
            error = exception instanceof FirstPartyApiError ? exception.message : "The monitoring could not be deleted.";
        } finally {
            deleting = false;
        }
    }

    async function openEdit(): Promise<void> {
        if (editLoading) return;

        editLoading = true;
        error = "";

        try {
            editForm = (await requestFirstPartyApi<MonitoringFormOptions>(`/api/monitorings/${data.monitoring.id}/form-options`)).data;
            editOpen = true;
        } catch (exception) {
            error = exception instanceof FirstPartyApiError ? exception.message : "The monitoring editor could not be loaded.";
        } finally {
            editLoading = false;
        }
    }

    function closeEdit(): void {
        editOpen = false;
        editForm = null;
    }

    async function handleEditSuccess(_monitoring: MonitoringMutationResult): Promise<void> {
        closeEdit();
        await invalidateAll();
    }
</script>

<svelte:head><title>{data.monitoring.name} | WebGuard</title></svelte:head>

<div class="border-b border-wg-border bg-wg-surface">
    <header class="mx-auto w-[min(76rem,calc(100%_-_2rem))] py-6 sm:py-7">
        <a class="inline-flex items-center gap-2 text-sm font-bold text-wg-text-muted no-underline transition hover:text-wg-accent" href={appRoutes.monitorings}>
            <NavIcon name="arrow-left" size={16} />Back to monitorings
        </a>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-violet-50 px-2.5 py-1 text-[0.6875rem] font-extrabold tracking-[0.12em] text-wg-accent uppercase dark:bg-violet-950/50">{data.monitoring.type ?? "Monitoring"}</span>
            <span class={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[0.6875rem] font-extrabold tracking-[0.12em] uppercase ${statusTone() === "healthy" ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300" : statusTone() === "danger" ? "bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300" : "bg-wg-surface-muted text-wg-text-muted"}`}><span class="size-1.5 rounded-full bg-current"></span>{statusLabel()}</span>
        </div>
        <h1 class="mt-3 break-words text-3xl leading-none font-extrabold tracking-tight sm:text-[2.15rem]">{data.monitoring.name}</h1>
        <div class="mt-4 flex flex-wrap items-center gap-3 text-sm text-wg-text-muted">
            <span class="break-all">{data.monitoring.target}</span>
            {#if linkedTarget}
                <a class="inline-grid size-8 place-items-center rounded-md text-wg-accent transition hover:bg-violet-50 dark:hover:bg-violet-950/50" href={linkedTarget} target="_blank" rel="noreferrer" aria-label="Open target" title="Open target"><NavIcon name="external" size={16} /></a>
            {/if}
            {#if data.monitoring.can_manage}
                <details class="relative"><summary class="inline-grid size-10 cursor-pointer list-none place-items-center rounded-md border border-wg-focus text-wg-accent transition hover:bg-violet-50 [&::-webkit-details-marker]:hidden dark:hover:bg-violet-950/50" aria-label="Monitoring actions" title="Monitoring actions"><NavIcon name="ellipsis" size={18} /></summary><div class="absolute left-0 z-20 mt-2 grid w-44 gap-1 rounded-lg border border-wg-border bg-wg-surface p-1.5 shadow-lg"><Button class="justify-start px-3 py-2 text-sm normal-case" variant="quiet" type="button" loading={editLoading} onclick={openEdit}>Edit monitoring</Button><Button class="justify-start px-3 py-2 text-sm normal-case" variant="danger" type="button" disabled={deleting} onclick={remove}>Delete monitoring</Button></div></details>
            {/if}
        </div>
    </header>
</div>

<main class="bg-wg-canvas">
    <div class="mx-auto w-[min(76rem,calc(100%_-_2rem))] py-6 sm:py-7">
        {#if data.monitoring.latest_check === null && data.monitoring.initial_results_wait_minutes !== null && data.monitoring.initial_results_wait_minutes !== undefined}
            <p class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100">The first monitoring results can take up to {data.monitoring.initial_results_wait_minutes} minutes, based on the configured check interval.</p>
        {/if}

        <section class="grid gap-4 md:grid-cols-3" aria-label="Monitoring summary">
            <article class="min-h-32 rounded-[1.125rem] border border-wg-border bg-wg-surface p-6 shadow-sm"><p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-text-muted uppercase">Current status</p><div class="mt-4 flex items-center gap-3"><span class={`size-3 rounded-full ${statusTone() === "healthy" ? "bg-emerald-500" : statusTone() === "danger" ? "bg-red-500" : "bg-amber-500"}`} aria-hidden="true"></span><p class="text-lg font-extrabold uppercase">{statusLabel()}</p>{#if data.detail.current_check.status_code !== null}<span class="text-sm text-wg-text-muted">HTTP {data.detail.current_check.status_code}</span>{/if}</div><p class="mt-3 text-sm text-wg-text-muted">Since {timestamp(data.detail.current_check.checked_at)}</p></article>
            <article class="min-h-32 rounded-[1.125rem] border border-wg-border bg-wg-surface p-6 shadow-sm"><p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-text-muted uppercase">Last check</p><p class="mt-4 text-lg font-extrabold">{timestamp(data.detail.current_check.checked_at)}</p><p class="mt-3 text-sm text-wg-text-muted">{intervalLabel(data.detail.current_check.interval)}</p></article>
            <article class="min-h-32 rounded-[1.125rem] border border-wg-border bg-wg-surface p-6 shadow-sm"><div class="flex items-center justify-between gap-3"><p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-text-muted uppercase">Last 24 hours</p><span class="text-lg font-extrabold text-wg-accent">—</span></div><div class="mt-4 flex h-7 gap-0.5" aria-label="Last 24 hours availability">{#each data.detail.heatmap.slice(0, 24) as point}<span class={`min-w-1 flex-1 rounded-sm ${heatmapClass(point)}`} aria-hidden="true"></span>{/each}</div><p class="mt-3 text-sm text-wg-text-muted">{data.detail.heatmap.length > 0 ? "No incidents in this period" : "No results in this period"}</p></article>
        </section>

        {#if data.detail.server_health_telemetry}
            <ServerHealthOverview detail={data.detail} monitoringId={data.monitoring.id} />
        {/if}

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
            <div class="min-w-0 space-y-6">
                <section class="grid gap-4 md:grid-cols-3" aria-label="Availability periods">
                    {#each [["7", "Last 7 Days"], ["30", "Last 30 Days"], ["90", "Last 90 Days"]] as [days, label]}
                        <article class="min-h-36 rounded-[1.125rem] border border-wg-border bg-wg-surface p-6 shadow-sm"><h2 class="text-2xl leading-none font-extrabold">{label}</h2><p class="mt-2 text-sm font-bold text-wg-accent">{availability(days as "7" | "30" | "90")}</p><p class="mt-2 text-sm leading-6 text-wg-text-muted">{periodDescription(days as "7" | "30" | "90")}</p></article>
                    {/each}
                </section>

    <MonitoringAnalytics detail={data.detail} monitoringId={data.monitoring.id} incidentsMeta={data.incidentsMeta} recentChecksMeta={data.recentChecksMeta} uptimeCalendarMeta={data.uptimeCalendarMeta} />
                <MonitoringTypeDiagnostics detail={data.detail} />
                {#if error}<p class="text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}
            </div>

            <aside class="grid gap-4" aria-label="Monitoring context">
                <section class="overflow-hidden rounded-[1.125rem] border border-wg-border bg-wg-surface shadow-sm"><header class="border-b border-wg-border px-6 py-5"><h2 class="text-lg font-extrabold">Ownership and groups</h2><p class="mt-1 text-sm text-wg-text-muted">Understand where this monitoring belongs.</p></header><div class="grid gap-5 p-6 text-sm"><div><p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-text-muted uppercase">Owner</p><p class="mt-1 font-extrabold">{data.detail.summary.ownership.name ?? "Private monitoring"}</p><p class="mt-1 text-wg-text-muted">{data.detail.summary.ownership.type === "team" ? "Team" : "Private"}</p></div><div><p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-text-muted uppercase">Groups</p>{#if data.detail.summary.groups.length > 0}<div class="mt-3 flex flex-wrap gap-2">{#each data.detail.summary.groups as group}<span class="rounded-full bg-violet-50 px-2.5 py-1 text-xs font-bold text-wg-accent dark:bg-violet-950/50">{group.name}</span>{/each}</div>{:else}<p class="mt-2 text-wg-text-muted">No groups</p>{/if}</div></div></section>
                {#if data.detail.ssl || data.detail.domain}<section class="overflow-hidden rounded-[1.125rem] border border-wg-border bg-wg-surface shadow-sm"><header class="border-b border-wg-border px-6 py-5"><h2 class="text-lg font-extrabold">Domain and SSL</h2></header><div class="p-6">{#if data.detail.ssl}<div class="flex items-start justify-between gap-3"><div><p class="text-sm font-extrabold">SSL certificate</p><p class="mt-1 text-sm text-wg-text-muted">{data.detail.ssl.valid ? `Valid until ${timestamp(data.detail.ssl.expiration)}` : "Certificate validation failed"}</p>{#if data.detail.ssl.issuer}<p class="mt-3 text-[0.6875rem] font-extrabold tracking-[0.12em] text-wg-text-muted uppercase">Issuer</p><p class="mt-1 break-words text-sm font-bold">{data.detail.ssl.issuer}</p>{/if}</div><span class={`mt-1 size-2.5 rounded-full ${data.detail.ssl.valid ? "bg-emerald-500" : "bg-red-500"}`}></span></div>{/if}{#if data.detail.domain}<div class="mt-5 border-t border-wg-border pt-5"><p class="text-sm font-extrabold">Domain registration</p><p class="mt-1 text-sm text-wg-text-muted">{data.detail.domain.valid ? `Valid until ${timestamp(data.detail.domain.expires_at)}` : "Domain validation failed"}</p>{#if data.detail.domain.registrar}<p class="mt-3 text-[0.6875rem] font-extrabold tracking-[0.12em] text-wg-text-muted uppercase">Registrar</p><p class="mt-1 break-words text-sm font-bold">{data.detail.domain.registrar}</p>{/if}</div>{/if}</div></section>{/if}
                <section class="overflow-hidden rounded-[1.125rem] border border-wg-border bg-wg-surface shadow-sm"><header class="border-b border-wg-border px-6 py-5"><h2 class="text-lg font-extrabold">Check regions</h2></header><div class="p-6">{#if data.detail.summary.check_regions.length > 0}<div class="flex flex-wrap gap-2">{#each data.detail.summary.check_regions as region}<span class="inline-flex items-center gap-2 rounded-full bg-wg-surface-muted px-2.5 py-1 text-xs font-bold"><span class="size-1.5 rounded-full bg-wg-accent"></span>{region}</span>{/each}</div>{:else}<p class="text-sm text-wg-text-muted">No specific regions configured.</p>{/if}</div></section>
                <section class="overflow-hidden rounded-[1.125rem] border border-wg-border bg-wg-surface shadow-sm"><header class="border-b border-wg-border px-6 py-5"><h2 class="text-lg font-extrabold">Next maintenance</h2></header><div class="p-6">{#if data.detail.maintenance.starts_at}<p class="text-sm font-extrabold">{data.detail.maintenance.active ? "Maintenance active" : "Maintenance scheduled"}</p><p class="mt-1 text-sm text-wg-text-muted">Starts {timestamp(data.detail.maintenance.starts_at)}</p>{:else if data.detail.maintenance.has_recurring_window}<p class="text-sm text-wg-text-muted">A recurring maintenance window is configured.</p>{:else}<p class="text-sm text-wg-text-muted">No maintenance planned.</p>{/if}</div></section>
                <section class="overflow-hidden rounded-[1.125rem] border border-wg-border bg-wg-surface shadow-sm"><header class="border-b border-wg-border px-6 py-5"><h2 class="text-lg font-extrabold">Notifications</h2></header><div class="p-6">{#if data.detail.summary.notification_channels.length > 0}<p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-text-muted uppercase">Channels</p><div class="mt-3 flex flex-wrap gap-2">{#each data.detail.summary.notification_channels as channel}<span class="rounded-full bg-wg-surface-muted px-2.5 py-1 text-xs font-bold">{channel}</span>{/each}</div>{:else}<p class="text-sm text-wg-text-muted">No channels enabled.</p>{/if}</div></section>
                <section class="overflow-hidden rounded-[1.125rem] border border-wg-border bg-wg-surface shadow-sm"><header class="border-b border-wg-border px-6 py-5"><h2 class="text-lg font-extrabold">Status pages</h2></header><div class="p-6">{#if data.detail.summary.status_pages.length > 0}<ul class="m-0 grid list-none gap-2 p-0">{#each data.detail.summary.status_pages as statusPage}<li><a class="text-sm font-bold text-wg-accent no-underline hover:text-wg-accent-strong" href={appRoutes.statusPages}>{statusPage.name}</a></li>{/each}</ul>{:else}<p class="text-sm text-wg-text-muted">Not published on a status page.</p>{/if}</div></section>
            </aside>
        </div>
    </div>
</main>

{#if editForm}
    <Dialog bind:open={editOpen} title="Monitoring" description="Basics" size="wide" onclose={closeEdit}>
        <MonitoringForm
            options={editForm}
            action={`/api/monitorings/${editForm.monitoring?.id}`}
            method="PATCH"
            presentation="edit-modal"
            onSuccess={handleEditSuccess}
            onCancel={closeEdit}
        />
    </Dialog>
{/if}
