<script lang="ts">
    import { Separator } from "#lib/components/ui/separator/index.ts";
    import * as Card from "#lib/components/ui/card/index.ts";
    import { Field as FormField, FieldLabel } from "#lib/components/ui/field/index.ts";
    import * as Empty from "#lib/components/ui/empty/index.ts";
    import { Badge } from "#lib/components/ui/badge/index.ts";
    import DataTable from "#lib/components/DataTable.svelte";
    import * as Table from "#lib/components/ui/table/index.ts";
    import * as Collapsible from "#lib/components/ui/collapsible/index.ts";
    import { Alert } from "#lib/components/ui/alert/index.ts";
    import { invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import { appRoutes } from "#lib/routes.ts";
    import { formatDateTime, formatNumber, formatPercentage } from "#lib/i18n/format.ts";
    import Button from "#lib/components/Button.svelte";
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
            streamed: {
                monitorings: Promise<MonitoringListResponse | null>;
                dashboard: Promise<DashboardResponse | null>;
                cards: Promise<MonitoringCardsResponse>;
            };
        };
    }

    let { data }: Props = $props();
    let monitorings = $state<MonitoringListResponse | null>(null);
    let monitoringsLoadFailed = $state(false);
    let dashboard = $state<DashboardResponse["data"] | null>(null);
    let dashboardLoadFailed = $state(false);
    let cards = $state<MonitoringCardsResponse["data"]>({});
    let quickView = $state<QuickView>("all");
    let selectedTypes = $state<string[]>([]);
    let selectedGroup = $state("");
    let sortOrder = $state<SortOrder>("name_asc");
    let createOpen = $state(false);
    let createForm = $state<MonitoringFormOptions | null>(null);
    let createLoading = $state(false);
    let createError = $state("");

    const allSystemsOperational = $derived(dashboard?.overall_state === "healthy");
    const openIncidents = $derived(dashboard?.recent_incidents.filter((incident) => !incident.resolved) ?? []);
    const monitoringTypes = [
        ["http", "HTTP"], ["ping", "Ping"], ["keyword", "Keyword"], ["port", "Port"],
        ["heartbeat", "Heartbeat"], ["server_health", "Server health"],
        ["domain_expiration", "Domain expiration"], ["dns_record", "DNS record"],
    ] as const;
    const groupOptions = $derived(
        [...new Map((monitorings?.data ?? []).flatMap((monitoring) => monitoring.groups.map((group) => [group.id, group]))).values()]
            .sort((left, right) => left.name.localeCompare(right.name)),
    );
    const visibleMonitorings = $derived(
        (monitorings?.data ?? [])
            .filter((monitoring) => matchesQuickView(monitoring, quickView))
            .filter((monitoring) => selectedTypes.length === 0 || monitoring.type === null || selectedTypes.includes(monitoring.type))
            .filter((monitoring) => selectedGroup === "" || monitoring.groups.some((group) => group.id === selectedGroup))
            .sort((left, right) => sortOrder === "name_asc" ? left.name.localeCompare(right.name) : right.name.localeCompare(left.name)),
    );

    $effect(() => {
        let active = true;
        void data.streamed.monitorings.then((response) => {
            if (active) {
                monitorings = response;
                monitoringsLoadFailed = response === null;
            }
        });
        void data.streamed.dashboard.then((response) => {
            if (active) {
                dashboard = response?.data ?? null;
                dashboardLoadFailed = response === null;
            }
        });
        void data.streamed.cards.then((response) => {
            if (active) cards = response.data;
        });

        return () => { active = false; };
    });

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
    function uptimePercentage(monitoring: MonitoringSummary): string {
        const points = card(monitoring)?.heatmap ?? [];
        const uptime = points.reduce((total, point) => total + point.uptime, 0);
        const downtime = points.reduce((total, point) => total + point.downtime, 0);

        return uptime + downtime === 0 ? "—" : formatPercentage((uptime / (uptime + downtime)) * 100, 1);
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
        <div><p class="m-0 text-sm font-medium text-wg-text-muted">Service operations</p><h1 class="mt-2 text-[clamp(2rem,5vw,2.75rem)] leading-[1.1] font-semibold">Monitorings</h1><p class="mt-3 max-w-2xl leading-6 text-wg-text-muted">Keep system health, monitoring groups, status pages, and incident patterns in one operational view.</p></div>
        {#if dashboard?.capabilities.can_create_monitoring}
            <Button type="button" loading={createLoading} onclick={openCreateModal}>Create</Button>
        {/if}
    </header>

    {#if createError}<Alert variant="destructive" class="mb-5 text-sm font-bold">{createError}</Alert>{/if}

    {#if monitorings === null}
        <Card.Root class="p-6 text-sm text-wg-text-muted" role={monitoringsLoadFailed ? "alert" : "status"} aria-live="polite">
            {monitoringsLoadFailed ? "Monitorings could not be loaded. Refresh to try again." : "Loading your monitorings…"}
        </Card.Root>
    {:else}
    <Card.Root as="section" class="mb-4 px-5 py-5 sm:px-7" aria-label="Monitoring total"><p class="text-base font-bold">Total monitorings: {formatNumber(monitorings.meta.total)}</p></Card.Root>

    <Card.Root as="section" class="mb-5 p-5 sm:p-7" aria-labelledby="filters-heading">
        <p id="filters-heading" class="text-sm font-bold">Quick views</p>
        <div class="mt-3 flex flex-wrap gap-2" aria-label="Quick monitoring views">
            {#each [["all", "All"], ["attention", "Needs attention"], ["paused", "Paused"], ["maintenance", "In maintenance"]] as [view, label]}
                <Button class={`min-h-8 rounded-full px-3 py-1.5 text-xs normal-case ${quickView === view ? "" : "border-wg-border bg-wg-surface-muted text-wg-text hover:bg-wg-surface"}`} variant={quickView === view ? "primary" : "secondary"} aria-pressed={quickView === view} type="button" onclick={() => (quickView = view as QuickView)}>{label}</Button>
            {/each}
        </div>
        <form class="mt-5 grid gap-3 sm:max-w-md" method="GET"><FormField><FieldLabel for="monitoring-filter-search" class="sr-only">Search monitorings</FieldLabel><Input id="monitoring-filter-search" name="search" type="search" value={data.filters.search} placeholder="Search by name, target, port or keyword" /></FormField><input name="lifecycle_status" type="hidden" value={data.filters.lifecycleStatus} /></form>
        <Card.Root variant="inset" class="mt-4 rounded-lg p-3.5">
            <Collapsible.Root>
                <Collapsible.Trigger class="cursor-pointer text-sm font-bold">Advanced filters</Collapsible.Trigger>
                <Collapsible.Content>
                    <div class="mt-4 grid gap-4">
                        <div class="flex flex-wrap gap-2" aria-label="Monitoring type filters">{#each monitoringTypes as [type, label]}<Button class={`min-h-8 rounded px-2.5 py-1.5 text-xs normal-case ${selectedTypes.includes(type) ? "" : "border-transparent bg-wg-surface-muted text-wg-text hover:bg-wg-surface"}`} variant={selectedTypes.includes(type) ? "primary" : "quiet"} aria-pressed={selectedTypes.includes(type)} type="button" onclick={() => toggleType(type)}>{label}</Button>{/each}</div>
                        <div class="grid gap-3 sm:grid-cols-2 lg:flex lg:flex-wrap">
                            <form method="GET"><input name="search" type="hidden" value={data.filters.search} /><FormField class="gap-1"><FieldLabel for="monitoring-filter-lifecycle" class="text-xs font-semibold text-wg-text-muted">Lifecycle</FieldLabel><Select id="monitoring-filter-lifecycle" name="lifecycle_status" value={data.filters.lifecycleStatus} onchange={(event) => event.currentTarget.form?.requestSubmit()}><option value="">All monitorings</option><option value="active">Active</option><option value="paused">Paused</option></Select></FormField></form>
                            <FormField class="gap-1"><FieldLabel for="monitoring-filter-group" class="text-xs font-semibold text-wg-text-muted">Group</FieldLabel><Select id="monitoring-filter-group" bind:value={selectedGroup}><option value="">All groups</option>{#each groupOptions as group}<option value={group.id}>{group.name}</option>{/each}</Select></FormField>
                            <FormField class="gap-1"><FieldLabel for="monitoring-filter-sort" class="text-xs font-semibold text-wg-text-muted">Sort</FieldLabel><Select id="monitoring-filter-sort" bind:value={sortOrder}><option value="name_asc">Name (A–Z)</option><option value="name_desc">Name (Z–A)</option></Select></FormField>
                        </div>
                    </div>
                </Collapsible.Content>
            </Collapsible.Root>
        </Card.Root>
    </Card.Root>

    {#if monitorings.data.length === 0}
        {#if dashboard?.capabilities.can_create_monitoring}
            <EmptyState title="No monitorings found" description="Adjust your filters or create a monitoring to start collecting availability data.">
                {#snippet action()}<Button type="button" loading={createLoading} onclick={openCreateModal}>Create monitoring</Button>{/snippet}
            </EmptyState>
        {:else}
            <EmptyState title="No monitorings found" description="Adjust your filters or create a monitoring to start collecting availability data." />
        {/if}
    {:else}
        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_18rem] xl:items-start">
                <Card.Root as="section" aria-labelledby="active-monitorings-heading">
                <Card.Header class="gap-3 px-5 py-5 sm:items-end"><div><Card.Title id="active-monitorings-heading" class="text-xl">Monitorings</Card.Title><Card.Description class="mt-1">Status, recent availability, response time, and last check.</Card.Description></div><p class="text-sm font-medium text-wg-text-muted">Total monitorings: {formatNumber(monitorings.meta.total)}</p></Card.Header>
                {#if visibleMonitorings.length === 0}
                    <Empty.Root class="items-start gap-0 rounded-none border-0 p-6 text-left text-sm text-wg-text-muted md:p-6">No monitorings match the selected quick view and filters.</Empty.Root>
                {:else}
                    <DataTable caption="Monitorings">
                        <Table.Header><Table.Row><Table.Head scope="col">Name</Table.Head><Table.Head scope="col">Target and last check</Table.Head><Table.Head scope="col">24-hour uptime</Table.Head><Table.Head scope="col">Response time</Table.Head><Table.Head scope="col">Status</Table.Head></Table.Row></Table.Header>
                        <Table.Body>{#each visibleMonitorings as monitoring (monitoring.id)}<Table.Row class="transition-colors hover:bg-wg-surface-muted/50">
                            <Table.Cell class="!p-0"><a class="block h-full px-4 py-3.5 text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-wg-focus" href={`/monitorings/${monitoring.id}`} aria-label={`View ${monitoring.name}`}><div class="flex min-w-40 items-center gap-2"><Badge variant="dot" class={`size-2.5 ${statusTone(monitoring) === "healthy" ? "bg-emerald-500" : statusTone(monitoring) === "danger" ? "bg-red-500" : statusTone(monitoring) === "degraded" ? "bg-amber-500" : "bg-wg-text-muted"}`} aria-hidden="true" /><span class="truncate font-bold transition-colors hover:text-wg-accent">{monitoring.name}</span></div><div class="mt-2 flex flex-wrap items-center gap-2"><Badge variant="neutral" class="px-2 py-0.5">{monitoring.type ?? "Monitoring"}</Badge>{#if monitoring.groups.length > 0}<span class="max-w-36 truncate text-xs text-wg-text-muted" title={monitoring.groups.map((group) => group.name).join(", ")}>{monitoring.groups.map((group) => group.name).join(", ")}</span>{/if}</div></a></Table.Cell>
                            <Table.Cell class="!p-0"><a class="block h-full px-4 py-3.5 text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-wg-focus" href={`/monitorings/${monitoring.id}`} aria-label={`View ${monitoring.name}`}><span class="block max-w-52 truncate text-sm" title={monitoring.target}>{monitoring.target}</span><p class="mt-1 text-xs text-wg-text-muted">{checkedAt(monitoring)}</p></a></Table.Cell>
                            <Table.Cell class="!p-0"><a class="block h-full px-4 py-3.5 text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-wg-focus" href={`/monitorings/${monitoring.id}`} aria-label={`View ${monitoring.name} details`}><span class="text-sm font-medium tabular-nums">{uptimePercentage(monitoring)}</span><div class="mt-2 flex min-w-36 gap-0.5" aria-label={`24-hour availability for ${monitoring.name}`}>{#each heatmapPoints(monitoring) as point}<span class={`h-5 w-1.5 rounded-sm ${heatmapClass(point)}`} aria-hidden="true"></span>{/each}</div></a></Table.Cell>
                            <Table.Cell class="!p-0"><a class="block h-full px-4 py-3.5 text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-wg-focus" href={`/monitorings/${monitoring.id}`} aria-label={`View ${monitoring.name} details`}><span class="whitespace-nowrap text-sm tabular-nums">{monitoring.latest_check?.response_time_ms === null || monitoring.latest_check === null ? "—" : `${formatNumber(Math.round(monitoring.latest_check.response_time_ms))} ms`}</span></a></Table.Cell>
                            <Table.Cell class="!p-0"><a class="block h-full px-4 py-3.5 text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-wg-focus" href={`/monitorings/${monitoring.id}`} aria-label={`View ${monitoring.name} details`}><div class="flex flex-wrap gap-1"><StatusBadge tone={statusTone(monitoring)} label={statusLabel(monitoring)} />{#if monitoring.open_incident}<StatusBadge tone="danger" label="Incident" />{/if}{#if monitoring.maintenance.has_recurring_window}<StatusBadge tone="neutral" label="Maintenance" />{/if}</div></a></Table.Cell>
                        </Table.Row>{/each}</Table.Body>
                    </DataTable>
                {/if}
                <Separator class="bg-wg-border" /><div class="px-5 py-4 sm:px-6"><Pagination page={monitorings.meta.current_page} pages={monitorings.meta.last_page} href={paginationHref} /></div>
            </Card.Root>
            {#if dashboard}
            <aside class="grid gap-4" aria-label="Operational summary">
                <Card.Root as="section" class="border-l-4 border-l-wg-accent p-5"><p class="text-sm font-medium text-wg-text-muted">All systems</p><h2 class="mt-3 text-lg font-semibold">{allSystemsOperational ? "All systems operational" : "Service attention required"}</h2><dl class="mt-5 grid grid-cols-2 gap-3 border-t border-wg-border pt-4"><div><dt class="text-2xl font-semibold tabular-nums text-wg-status-up-foreground">{formatNumber(dashboard.summary.healthy)}</dt><dd class="mt-1 text-xs text-wg-text-muted">Healthy</dd></div><div><dt class="text-2xl font-semibold tabular-nums text-wg-status-down-foreground">{formatNumber(dashboard.summary.down + dashboard.summary.unknown)}</dt><dd class="mt-1 text-xs text-wg-text-muted">Needs attention</dd></div></dl></Card.Root>
                <Card.Root as="section" class="p-5"><div class="flex items-start justify-between gap-3"><h2 class="text-base font-bold">Open incidents</h2><Badge variant="danger" class="rounded-full bg-red-50 px-2 py-1 font-bold text-red-600 dark:bg-red-950">{formatNumber(openIncidents.length)}</Badge></div><p class="mt-3 text-sm leading-5 text-wg-text-muted">Review current service disruptions and recent incident activity.</p><a class="mt-4 inline-flex text-sm font-bold text-wg-accent no-underline hover:text-wg-accent-strong" href={appRoutes.incidents}>Open incidents →</a></Card.Root>
                <Card.Root as="section" class="p-5"><h2 class="text-base font-bold">Status pages</h2><p class="mt-3 text-sm leading-5 text-wg-text-muted">Publish service availability for your customers and teams.</p><a class="mt-4 inline-flex text-sm font-bold text-wg-accent no-underline hover:text-wg-accent-strong" href={appRoutes.statusPages}>Manage →</a></Card.Root>
            </aside>
            {:else}
            <Card.Root role="complementary" class="p-5 text-sm text-wg-text-muted" aria-label="Operational summary" aria-live="polite">
                {dashboardLoadFailed ? "Operational summary could not be loaded." : "Loading operational summary…"}
            </Card.Root>
            {/if}
        </div>
    {/if}
    {/if}
</main>

<Dialog bind:open={createOpen} title="Create monitoring" description="Configure a monitoring and start collecting results." size="wide">{#if createForm}<MonitoringForm options={createForm} action="/api/monitorings" method="POST" presentation="edit-modal" onSuccess={handleCreateSuccess} onCancel={() => (createOpen = false)} />{/if}</Dialog>
