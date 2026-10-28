<script lang="ts">
    import { invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import { appRoutes } from "#lib/routes.ts";
    import { formatDateTime, formatNumber } from "#lib/i18n/format.ts";
    import Button from "#lib/components/Button.svelte";
    import DataTable from "#lib/components/DataTable.svelte";
    import Dialog from "#lib/components/Dialog.svelte";
    import EmptyState from "#lib/components/EmptyState.svelte";
    import Input from "#lib/components/Input.svelte";
    import MonitoringForm from "#lib/components/MonitoringForm.svelte";
    import Pagination from "#lib/components/Pagination.svelte";
    import Select from "#lib/components/Select.svelte";
    import StatusBadge from "#lib/components/StatusBadge.svelte";
    import type { DashboardResponse, MonitoringCardsResponse, MonitoringFormOptions, MonitoringHeatmapPoint, MonitoringListResponse, MonitoringMutationResult, MonitoringSummary } from "#lib/api/monitoring.ts";

    type QuickView = "all" | "attention" | "paused" | "maintenance";
    type SortOrder = "name_asc" | "name_desc";

    interface Props {
        data: {
            filters: { lifecycleStatus: string; search: string };
            monitorings: MonitoringListResponse;
            dashboard: DashboardResponse;
            cards: MonitoringCardsResponse;
        };
    }

    let { data }: Props = $props();
    let quickView = $state<QuickView>("all");
    let selectedTypes = $state<string[]>([]);
    let selectedGroup = $state("");
    let sortOrder = $state<SortOrder>("name_asc");
    let createOpen = $state(false);
    let createForm = $state<MonitoringFormOptions | null>(null);
    let createLoading = $state(false);
    let createError = $state("");

    const dashboard = $derived(data.dashboard.data);
    const cards = $derived(data.cards.data);
    const allSystemsOperational = $derived(dashboard.overall_state === "healthy");
    const openIncidents = $derived(dashboard.recent_incidents.filter((incident) => !incident.resolved));
    const monitoringTypes = [
        ["http", "HTTP"], ["ping", "Ping"], ["keyword", "Keyword"], ["port", "Port"],
        ["heartbeat", "Heartbeat"], ["server_health", "Server health"],
        ["domain_expiration", "Domain expiration"], ["dns_record", "DNS record"],
    ] as const;
    const groupOptions = $derived(
        [...new Map(data.monitorings.data.flatMap((monitoring) => monitoring.groups.map((group) => [group.id, group]))).values()]
            .sort((left, right) => left.name.localeCompare(right.name)),
    );
    const visibleMonitorings = $derived(
        data.monitorings.data
            .filter((monitoring) => matchesQuickView(monitoring, quickView))
            .filter((monitoring) => selectedTypes.length === 0 || monitoring.type === null || selectedTypes.includes(monitoring.type))
            .filter((monitoring) => selectedGroup === "" || monitoring.groups.some((group) => group.id === selectedGroup))
            .sort((left, right) => sortOrder === "name_asc" ? left.name.localeCompare(right.name) : right.name.localeCompare(left.name)),
    );

    function card(monitoring: MonitoringSummary) { return cards[monitoring.id]; }
    function monitoringStatus(monitoring: MonitoringSummary): "up" | "down" | "unknown" | null { return card(monitoring)?.status ?? monitoring.latest_check?.status ?? null; }
    function statusTone(monitoring: MonitoringSummary): "healthy" | "degraded" | "danger" | "neutral" | "paused" {
        if (monitoring.lifecycle_status === "paused") return "paused";
        if (monitoringStatus(monitoring) === "up") return "healthy";
        if (monitoringStatus(monitoring) === "down") return "danger";
        if (monitoringStatus(monitoring) === "unknown") return "degraded";
        return "neutral";
    }
    function statusLabel(monitoring: MonitoringSummary): string {
        if (monitoring.lifecycle_status === "paused") return "Paused";
        if (monitoring.latest_check === null) return "Waiting for results";
        return monitoringStatus(monitoring) === "up" ? "Operational" : monitoringStatus(monitoring) === "down" ? "Down" : "Unknown";
    }
    function checkedAt(monitoring: MonitoringSummary): string {
        if (monitoring.latest_check?.checked_at === null || monitoring.latest_check === null) return "No result yet";
        return formatDateTime(monitoring.latest_check.checked_at, "No result yet");
    }
    function matchesQuickView(monitoring: MonitoringSummary, view: QuickView): boolean {
        if (view === "attention") return ["down", "unknown"].includes(monitoringStatus(monitoring) ?? "unknown");
        if (view === "paused") return monitoring.lifecycle_status === "paused";
        return view !== "maintenance" || monitoring.maintenance.has_recurring_window;
    }
    function heatmapPoints(monitoring: MonitoringSummary): Array<MonitoringHeatmapPoint | null> {
        const points = card(monitoring)?.heatmap ?? [];
        return [...points.slice(0, 24), ...Array<MonitoringHeatmapPoint | null>(Math.max(0, 24 - points.length)).fill(null)];
    }
    function heatmapClass(point: MonitoringHeatmapPoint | null): string {
        if (point === null || point.uptime === point.downtime) return "bg-wg-surface-muted";
        return point.uptime > point.downtime ? "bg-emerald-500" : "bg-red-500";
    }
    function toggleType(type: string): void {
        selectedTypes = selectedTypes.includes(type) ? selectedTypes.filter((selectedType) => selectedType !== type) : [...selectedTypes, type];
    }
    function paginationHref(page: number): string {
        const params = new URLSearchParams();
        if (page > 1) params.set("page", String(page));
        if (data.filters.search !== "") params.set("search", data.filters.search);
        if (data.filters.lifecycleStatus !== "") params.set("lifecycle_status", data.filters.lifecycleStatus);
        return params.size === 0 ? appRoutes.monitorings : `${appRoutes.monitorings}?${params}`;
    }

    async function handleCreateSuccess(_monitoring: MonitoringMutationResult): Promise<void> {
        createOpen = false;
        await invalidateAll();
    }

    async function openCreateModal(): Promise<void> {
        if (createLoading) return;

        createLoading = true;
        createError = "";

        try {
            createForm = (await requestFirstPartyApi<MonitoringFormOptions>("/api/monitorings/form-options")).data;
            createOpen = true;
        } catch (exception) {
            createError = exception instanceof FirstPartyApiError ? exception.message : "The monitoring form could not be loaded.";
        } finally {
            createLoading = false;
        }
    }

</script>

<svelte:head><title>Monitorings | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(76rem,calc(100%_-_2rem))] py-6 sm:py-10">
    <header class="mb-6 flex flex-col gap-4 border-b border-wg-border pb-6 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Service operations</p><h1 class="mt-2 text-[clamp(2rem,5vw,2.75rem)] leading-[1.1] font-bold">Monitorings</h1><p class="mt-3 max-w-2xl leading-6 text-wg-text-muted">Keep system health, monitoring groups, status pages, and incident patterns in one operational view.</p></div>
        {#if dashboard.capabilities.can_create_monitoring}
            <Button class="tracking-[0.08em] uppercase" type="button" loading={createLoading} onclick={openCreateModal}>Create</Button>
        {/if}
    </header>

    {#if createError}<p class="mb-5 text-sm font-bold text-wg-danger" role="alert">{createError}</p>{/if}

    <section class="mb-4 rounded-xl border border-wg-border bg-wg-surface px-5 py-5 shadow-wg-surface sm:px-7" aria-label="Monitoring total"><p class="text-base font-bold">Total monitorings: {formatNumber(data.monitorings.meta.total)}</p></section>

    <section class="mb-5 rounded-xl border border-wg-border bg-wg-surface p-5 shadow-wg-surface sm:p-7" aria-labelledby="filters-heading">
        <p id="filters-heading" class="text-sm font-bold">Quick views</p>
        <div class="mt-3 flex flex-wrap gap-2" aria-label="Quick monitoring views">
            {#each [["all", "All"], ["attention", "Needs attention"], ["paused", "Paused"], ["maintenance", "In maintenance"]] as [view, label]}
                <Button class={`min-h-8 rounded-full px-3 py-1.5 text-xs normal-case ${quickView === view ? "" : "border-wg-border bg-wg-surface-muted text-wg-text hover:bg-wg-surface"}`} variant={quickView === view ? "primary" : "secondary"} type="button" onclick={() => (quickView = view as QuickView)}>{label}</Button>
            {/each}
        </div>
        <form class="mt-5 grid gap-3 sm:max-w-md" method="GET"><label class="grid gap-2 text-sm font-semibold"><span class="sr-only">Search monitorings</span><Input name="search" type="search" value={data.filters.search} placeholder="Search by name, target, port or keyword" /></label><input name="lifecycle_status" type="hidden" value={data.filters.lifecycleStatus} /></form>
        <details class="mt-4 rounded-lg border border-wg-border p-3.5">
            <summary class="cursor-pointer text-sm font-bold marker:text-wg-text-muted">Advanced filters</summary>
            <div class="mt-4 grid gap-4">
                <div class="flex flex-wrap gap-2" aria-label="Monitoring type filters">{#each monitoringTypes as [type, label]}<Button class={`min-h-8 rounded px-2.5 py-1.5 text-xs normal-case ${selectedTypes.includes(type) ? "" : "border-transparent bg-wg-surface-muted text-wg-text hover:bg-wg-surface"}`} variant={selectedTypes.includes(type) ? "primary" : "quiet"} type="button" onclick={() => toggleType(type)}>{label}</Button>{/each}</div>
                <div class="grid gap-3 sm:grid-cols-2 lg:flex lg:flex-wrap">
                    <form method="GET"><input name="search" type="hidden" value={data.filters.search} /><label class="grid gap-1 text-xs font-semibold text-wg-text-muted">Lifecycle<Select name="lifecycle_status" value={data.filters.lifecycleStatus} onchange={(event) => event.currentTarget.form?.requestSubmit()}><option value="">All monitorings</option><option value="active">Active</option><option value="paused">Paused</option></Select></label></form>
                    <label class="grid gap-1 text-xs font-semibold text-wg-text-muted">Group<Select bind:value={selectedGroup}><option value="">All groups</option>{#each groupOptions as group}<option value={group.id}>{group.name}</option>{/each}</Select></label>
                    <label class="grid gap-1 text-xs font-semibold text-wg-text-muted">Sort<Select bind:value={sortOrder}><option value="name_asc">Name (A–Z)</option><option value="name_desc">Name (Z–A)</option></Select></label>
                </div>
            </div>
        </details>
    </section>

    {#if data.monitorings.data.length === 0}
        {#if dashboard.capabilities.can_create_monitoring}
            <EmptyState title="No monitorings found" description="Adjust your filters or create a monitoring to start collecting availability data.">
                {#snippet action()}<Button type="button" loading={createLoading} onclick={openCreateModal}>Create monitoring</Button>{/snippet}
            </EmptyState>
        {:else}
            <EmptyState title="No monitorings found" description="Adjust your filters or create a monitoring to start collecting availability data." />
        {/if}
    {:else}
        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_18rem] xl:items-start">
            <section class="overflow-hidden rounded-2xl border border-wg-border bg-wg-surface shadow-wg-surface" aria-labelledby="active-monitorings-heading">
                <header class="flex flex-col gap-3 border-b border-wg-border px-5 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-6"><div><h2 id="active-monitorings-heading" class="text-xl font-bold">Active monitorings</h2><p class="mt-1 text-sm text-wg-text-muted">System health and details in one focused workspace.</p></div><p class="text-sm font-semibold text-wg-text-muted">Total monitorings: {formatNumber(data.monitorings.meta.total)}</p></header>
                {#if visibleMonitorings.length === 0}
                    <p class="p-6 text-sm text-wg-text-muted">No monitorings match the selected quick view and filters.</p>
                {:else}
                    <DataTable caption="Active monitorings">
                        <thead><tr><th>Name</th><th>Target</th><th>Last 24 hours</th><th>Status</th></tr></thead>
                        <tbody>{#each visibleMonitorings as monitoring (monitoring.id)}<tr class="transition-colors hover:bg-wg-surface-muted/50">
                            <td class="!p-0"><a class="block h-full px-4 py-3.5 text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-wg-focus" href={`/monitorings/${monitoring.id}`} aria-label={`View ${monitoring.name}`}><div class="flex min-w-40 items-center gap-2"><span class={`size-2.5 shrink-0 rounded-full ${statusTone(monitoring) === "healthy" ? "bg-emerald-500" : statusTone(monitoring) === "danger" ? "bg-red-500" : statusTone(monitoring) === "degraded" ? "bg-amber-500" : "bg-wg-text-muted"}`} aria-hidden="true"></span><span class="truncate font-bold transition-colors hover:text-wg-accent">{monitoring.name}</span></div><div class="mt-2 flex flex-wrap items-center gap-2"><span class="rounded bg-wg-surface-muted px-2 py-0.5 text-xs font-semibold text-wg-text-muted">{monitoring.type ?? "Monitoring"}</span>{#if monitoring.groups.length > 0}<span class="max-w-36 truncate text-xs text-wg-text-muted" title={monitoring.groups.map((group) => group.name).join(", ")}>{monitoring.groups.map((group) => group.name).join(", ")}</span>{/if}</div></a></td>
                            <td class="!p-0"><a class="block h-full px-4 py-3.5 text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-wg-focus" href={`/monitorings/${monitoring.id}`} aria-label={`View ${monitoring.name}`}><span class="block max-w-52 truncate text-sm" title={monitoring.target}>{monitoring.target}</span><p class="mt-1 text-xs text-wg-text-muted">{checkedAt(monitoring)}</p></a></td>
                            <td class="!p-0"><a class="block h-full px-4 py-3.5 text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-wg-focus" href={`/monitorings/${monitoring.id}`} aria-label={`View ${monitoring.name} details`}><div class="flex min-w-36 gap-0.5" aria-label={`24-hour availability for ${monitoring.name}`}>{#each heatmapPoints(monitoring) as point}<span class={`h-6 w-1.5 rounded-sm ${heatmapClass(point)}`} aria-hidden="true"></span>{/each}</div></a></td>
                            <td class="!p-0"><a class="block h-full px-4 py-3.5 text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-wg-focus" href={`/monitorings/${monitoring.id}`} aria-label={`View ${monitoring.name} details`}><div class="flex flex-wrap gap-1"><StatusBadge tone={statusTone(monitoring)} label={statusLabel(monitoring)} />{#if monitoring.open_incident}<StatusBadge tone="danger" label="Incident" />{/if}{#if monitoring.maintenance.has_recurring_window}<StatusBadge tone="neutral" label="Maintenance" />{/if}</div></a></td>
                        </tr>{/each}</tbody>
                    </DataTable>
                {/if}
                <div class="border-t border-wg-border px-5 py-4 sm:px-6"><Pagination page={data.monitorings.meta.current_page} pages={data.monitorings.meta.last_page} href={paginationHref} /></div>
            </section>
            <aside class="grid gap-4" aria-label="Operational summary">
                <section class="rounded-2xl border border-violet-200 bg-violet-50 p-5 shadow-wg-surface dark:border-violet-800 dark:bg-violet-950/40"><p class="text-xs font-extrabold tracking-[0.12em] text-wg-accent uppercase">All systems</p><h2 class="mt-3 text-lg font-bold">{allSystemsOperational ? "All systems operational" : "Service attention required"}</h2><dl class="mt-5 grid grid-cols-2 gap-3 border-t border-violet-200 pt-4 dark:border-violet-800"><div><dt class="text-2xl font-extrabold text-emerald-600">{formatNumber(dashboard.summary.healthy)}</dt><dd class="mt-1 text-xs text-wg-text-muted">Healthy</dd></div><div><dt class="text-2xl font-extrabold text-red-600">{formatNumber(dashboard.summary.down + dashboard.summary.unknown)}</dt><dd class="mt-1 text-xs text-wg-text-muted">Needs attention</dd></div></dl></section>
                <section class="rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-wg-surface"><div class="flex items-start justify-between gap-3"><h2 class="text-base font-bold">Open incidents</h2><span class="rounded-full bg-red-50 px-2 py-1 text-xs font-bold text-red-600 dark:bg-red-950 dark:text-red-200">{formatNumber(openIncidents.length)}</span></div><p class="mt-3 text-sm leading-5 text-wg-text-muted">Review current service disruptions and recent incident activity.</p><a class="mt-4 inline-flex text-sm font-bold text-wg-accent no-underline hover:text-wg-accent-strong" href={appRoutes.incidents}>Open incidents →</a></section>
                <section class="rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-wg-surface"><h2 class="text-base font-bold">Status pages</h2><p class="mt-3 text-sm leading-5 text-wg-text-muted">Publish service availability for your customers and teams.</p><a class="mt-4 inline-flex text-sm font-bold text-wg-accent no-underline hover:text-wg-accent-strong" href={appRoutes.statusPages}>Manage →</a></section>
            </aside>
        </div>
    {/if}
</main>

<Dialog bind:open={createOpen} title="Create monitoring" description="Configure a monitoring and start collecting results." size="wide">{#if createForm}<MonitoringForm options={createForm} action="/api/monitorings" method="POST" presentation="edit-modal" onSuccess={handleCreateSuccess} onCancel={() => (createOpen = false)} />{/if}</Dialog>
