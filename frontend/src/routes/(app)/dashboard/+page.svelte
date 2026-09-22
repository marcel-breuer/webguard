<script lang="ts">
    import { goto, invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "$lib/api/client";
    import { appRoutes } from "$lib/routes";
    import { formatDateTime } from "$lib/i18n/format";
    import Button from "$lib/components/Button.svelte";
    import Card from "$lib/components/Card.svelte";
    import Dialog from "$lib/components/Dialog.svelte";
    import EmptyState from "$lib/components/EmptyState.svelte";
    import Input from "$lib/components/Input.svelte";
    import MonitoringForm from "$lib/components/MonitoringForm.svelte";
    import Pagination from "$lib/components/Pagination.svelte";
    import StatusBadge from "$lib/components/StatusBadge.svelte";
    import type { DashboardResponse, DashboardService, MonitoringFormOptions, MonitoringMutationResult } from "$lib/api/monitoring";
    import type { FirstPartySession } from "$lib/api/models";

    interface Props {
        data: {
            dashboard: DashboardResponse;
            session: FirstPartySession;
        };
    }

    let { data }: Props = $props();
    let serviceQuery = $state("");
    let activeFilter = $state<"all" | "attention" | "maintenance" | "paused">("all");
    let createOpen = $state(false);
    let createForm = $state<MonitoringFormOptions | null>(null);
    let createdMonitoring = $state<MonitoringMutationResult | null>(null);
    let createLoading = $state(false);
    let createError = $state("");
    const dashboard = $derived(data.dashboard.data);
    const pagination = $derived(data.dashboard.meta.service_pagination);
    const services = $derived(dashboard.services.filter((service) => matchesService(service, serviceQuery, activeFilter)));

    function matchesService(service: DashboardService, query: string, filter: typeof activeFilter): boolean {
        const normalizedQuery = query.trim().toLocaleLowerCase();
        const queryMatches = normalizedQuery === "" || `${service.name} ${service.target} ${service.group}`.toLocaleLowerCase().includes(normalizedQuery);
        const filterMatches = filter === "all"
            || (filter === "attention" && ["down", "unknown"].includes(service.status))
            || service.status === filter;

        return queryMatches && filterMatches;
    }

    function statusTone(status: string): "healthy" | "degraded" | "danger" | "neutral" | "paused" {
        if (status === "up") return "healthy";
        if (status === "down") return "danger";
        if (status === "unknown") return "degraded";
        if (status === "paused") return "paused";

        return "neutral";
    }

    function statusLabel(status: string): string {
        return status === "up" ? "Working"
            : status === "down" ? "Down"
                : status === "unknown" ? "Needs a check"
                    : status === "paused" ? "Paused"
                        : status === "maintenance" ? "Maintenance"
                            : status;
    }

    function healthMessage(): string {
        if (dashboard.summary.down > 0) return "Some websites are down";
        if (dashboard.summary.unknown > 0) return "Some websites need a recent check";
        if (dashboard.summary.healthy > 0) return "All checked websites are working";
        if (dashboard.summary.maintenance > 0) return "All your websites are under maintenance";

        return "All your websites are paused";
    }

    function healthTone(): "healthy" | "degraded" | "danger" | "neutral" {
        if (dashboard.summary.down > 0) return "danger";
        if (dashboard.summary.unknown > 0) return "degraded";
        if (dashboard.summary.healthy > 0) return "healthy";

        return "neutral";
    }

    function healthBadgeLabel(): string {
        if (dashboard.summary.down > 0) return "Action needed";
        if (dashboard.summary.unknown > 0) return "Needs a check";
        if (dashboard.summary.healthy > 0) return "Working";
        if (dashboard.summary.maintenance > 0) return "Maintenance";

        return "Paused";
    }

    function statusDotClass(status: string): string {
        if (status === "up") return "bg-emerald-500";
        if (status === "down") return "bg-red-500";

        return "bg-slate-400";
    }

    function attentionStatusLabel(type: string): string {
        if (type === "down" || type === "incident") return "Website is down";
        if (type === "delivery") return "Notification delivery issue";

        return "No recent check";
    }

    function attentionTone(type: string): "danger" | "degraded" {
        return type === "down" || type === "incident" ? "danger" : "degraded";
    }

    function attentionBorderClass(type: string): string {
        return type === "down" || type === "incident" ? "border-red-300 dark:border-red-900" : "border-amber-300 dark:border-amber-900";
    }

    function dateTime(value: string | null): string {
        if (value === null) return "Not checked yet";

        return formatDateTime(value, "—");
    }

    function paginationHref(page: number): string {
        return page === 1 ? appRoutes.dashboard : `${appRoutes.dashboard}?service_page=${page}`;
    }

    async function openCreateModal(): Promise<void> {
        if (createLoading) return;

        createLoading = true;
        createError = "";
        createdMonitoring = null;

        try {
            createForm = (await requestFirstPartyApi<MonitoringFormOptions>("/api/monitorings/form-options")).data;
            createOpen = true;
        } catch (exception) {
            createError = exception instanceof FirstPartyApiError ? exception.message : "The monitoring form could not be loaded.";
        } finally {
            createLoading = false;
        }
    }

    function handleCreateSuccess(monitoring: MonitoringMutationResult): void {
        createdMonitoring = monitoring;
    }

    async function finishCreate(): Promise<void> {
        createOpen = false;
        createdMonitoring = null;
        await invalidateAll();
    }

    function handleCreateDialogClose(): void {
        if (createdMonitoring) {
            void finishCreate();
        }
    }
</script>

<svelte:head><title>Dashboard | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(76rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Your websites</p>
            <h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Welcome back, {data.session.user.name}</h1>
            <p class="mt-3 max-w-2xl leading-6 text-wg-text-muted">Check which websites are working, which need attention, and when they were last checked.</p>
        </div>
        {#if dashboard.capabilities.can_create_monitoring && dashboard.summary.total > 0}
            <Button type="button" loading={createLoading} onclick={openCreateModal}>Add your website</Button>
        {/if}
    </header>

    {#if createError}<p class="mb-6 text-sm font-bold text-wg-danger" role="alert">{createError}</p>{/if}

    {#if dashboard.summary.total === 0}
        <EmptyState title="No websites yet" description="Add your website and WebGuard will check it automatically.">
            {#snippet action()}<Button type="button" loading={createLoading} onclick={openCreateModal}>Add your website</Button>{/snippet}
        </EmptyState>
    {:else}
        <Card title="Website health" titleId="health-heading" description={healthMessage()}>
            {#snippet actions()}<StatusBadge tone={healthTone()} label={healthBadgeLabel()} />{/snippet}
            <p class="mb-4 mt-0 text-sm font-bold text-wg-text-muted">{dashboard.summary.total} <span>websites</span></p>
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                {#each [["healthy", "Working"], ["down", "Down"], ["unknown", "Needs a check"], ["paused", "Paused"], ["maintenance", "Maintenance"]] as [key, label]}
                    <div class="rounded-xl bg-wg-surface-muted p-3">
                        <dt class="text-xs font-bold text-wg-text-muted">{label}</dt>
                        <dd class="mt-1 text-xl font-extrabold">{dashboard.summary[key as keyof typeof dashboard.summary]}</dd>
                    </div>
                {/each}
            </dl>
        </Card>

        <div class="mt-6">
            <Card title="Needs attention" titleId="attention-heading" description="Websites with an issue, or notification delivery problems.">
                {#if dashboard.attention.length === 0}
                    <p class="m-0 text-sm text-wg-text-muted">No websites need attention.</p>
                {:else}
                    <ul class="m-0 grid list-none gap-3 p-0">
                        {#each dashboard.attention as item}
                            <li class={`rounded-xl border p-3 ${attentionBorderClass(item.type)}`}>
                                {#if item.monitoring_id}
                                    <a class="block rounded-md text-wg-text no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus" href={`/monitorings/${item.monitoring_id}`}>
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <p class="m-0 font-bold">{item.monitoring_name ?? "Website"}</p>
                                            <StatusBadge tone={attentionTone(item.type)} label={attentionStatusLabel(item.type)} />
                                        </div>
                                        <p class="mb-0 mt-1 text-sm text-wg-text-muted">{item.monitoring_target}</p>
                                        <span class="mt-2 inline-block text-sm font-semibold text-wg-accent">View website details <span aria-hidden="true">→</span></span>
                                    </a>
                                {:else}
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <p class="m-0 font-bold">{item.count ?? 0} <span>notification deliveries</span></p>
                                        <StatusBadge tone={attentionTone(item.type)} label={attentionStatusLabel(item.type)} />
                                    </div>
                                    <p class="mb-0 mt-1 text-sm text-wg-text-muted">Review failed delivery configuration.</p>
                                {/if}
                            </li>
                        {/each}
                    </ul>
                {/if}
            </Card>
        </div>

        <div class="mt-6">
            <Card title="Your websites" titleId="services-heading" description="Current status and last check for each website.">
                <label class="mb-4 block w-full sm:max-w-sm">
                    <span class="sr-only">Search websites</span>
                    <Input bind:value={serviceQuery} type="search" placeholder="Search by name or address" />
                </label>
                <div class="flex flex-wrap gap-2" aria-label="Website filters">
                    {#each [["all", "All"], ["attention", "Needs attention"], ["maintenance", "Maintenance"], ["paused", "Paused"]] as [filter, label]}
                        <Button class={`min-h-9 rounded-full px-3 py-1.5 text-xs ${activeFilter === filter ? "" : "border-wg-border bg-wg-surface text-wg-text hover:bg-wg-surface-muted"}`} variant={activeFilter === filter ? "primary" : "secondary"} type="button" onclick={() => (activeFilter = filter as typeof activeFilter)}>{label}</Button>
                    {/each}
                </div>
                {#if services.length === 0}
                    <p class="mb-0 mt-5 text-sm text-wg-text-muted">No websites match your search or filters.</p>
                {:else}
                    <div class="mt-5 divide-y divide-wg-border border-y border-wg-border">
                        {#each services as service (service.id)}
                            <a class="flex flex-col gap-3 py-4 text-wg-text no-underline transition hover:bg-wg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus sm:flex-row sm:items-center sm:justify-between" href={`/monitorings/${service.id}`}>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class={`size-2.5 shrink-0 rounded-full ${statusDotClass(service.status)}`} aria-hidden="true"></span>
                                        <span class="sr-only">Current status: {statusLabel(service.status)}</span>
                                        <h3 class="truncate text-base font-bold">{service.name}</h3>
                                        <StatusBadge tone={statusTone(service.status)} label={statusLabel(service.status)} />
                                        {#if service.open_incident}<StatusBadge tone="danger" label="Incident" />{/if}
                                    </div>
                                    <p class="mt-1 truncate text-sm text-wg-text-muted">{service.target} · {service.group}</p>
                                </div>
                                <div class="shrink-0 text-sm text-wg-text-muted sm:text-right">
                                    <p>{service.response_time_ms === null ? "—" : `${Math.round(service.response_time_ms)} ms`}</p>
                                    <p class="mt-1 text-xs">{dateTime(service.last_checked_at)}</p>
                                </div>
                            </a>
                        {/each}
                    </div>
                {/if}
                <div class="mt-4"><Pagination page={pagination.current_page} pages={pagination.last_page} href={paginationHref} /></div>
            </Card>
        </div>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
            <Card title="Maintenance" description="Scheduled and active maintenance windows.">
                {#if dashboard.maintenance.length === 0}
                    <p class="text-sm text-wg-text-muted">No maintenance windows are scheduled.</p>
                {:else}
                    <ul class="m-0 grid list-none gap-3 p-0">
                        {#each dashboard.maintenance as maintenance}
                            <li class="rounded-xl border border-wg-border p-3">
                                <div class="flex items-center justify-between gap-3"><p class="font-bold">{maintenance.monitoring_name}</p><StatusBadge tone="neutral" label={maintenance.status === "active" ? "Active" : "Upcoming"} /></div>
                                <p class="mt-1 text-sm text-wg-text-muted">Starts {dateTime(maintenance.starts_at)}</p>
                            </li>
                        {/each}
                    </ul>
                {/if}
            </Card>

            <Card title="Recent incidents" description="Latest incidents for monitorings you can access.">
                {#if dashboard.recent_incidents.length === 0}
                    <p class="text-sm text-wg-text-muted">No recent incidents.</p>
                {:else}
                    <ul class="m-0 grid list-none gap-3 p-0">
                        {#each dashboard.recent_incidents as incident}
                            <li class="rounded-xl border border-wg-border p-3"><p class="font-bold">{incident.monitoring_name ?? "Monitoring"}</p><p class="mt-1 text-sm text-wg-text-muted">Started {dateTime(incident.down_at)} · {incident.resolved ? "Resolved incident" : "Open incident"}</p></li>
                        {/each}
                    </ul>
                {/if}
            </Card>

        </section>
    {/if}
</main>

<Dialog bind:open={createOpen} onclose={handleCreateDialogClose} title={createdMonitoring ? (createdMonitoring.lifecycle_status === "active" ? "Website monitoring started" : "Website saved") : "Add your website"} description={createdMonitoring ? (createdMonitoring.lifecycle_status === "active" ? "WebGuard is checking your website. The first results may take a few minutes." : "Monitoring is paused. Start it from the website details page to receive checks.") : "Enter a website address to start a standard availability check."} size="wide">
    {#if createdMonitoring}
        {@const createdMonitoringId = createdMonitoring.id}
        {@const createdLifecycleStatus = createdMonitoring.lifecycle_status}
        <div class="grid gap-5">
            <Card title={createdMonitoring.name} description={createdLifecycleStatus === "active" ? "WebGuard is monitoring this website." : "Monitoring is paused. Start it from the website details page to receive checks."}>
                {#snippet actions()}<StatusBadge tone={createdLifecycleStatus === "active" ? "healthy" : "paused"} label={createdLifecycleStatus === "active" ? "Monitoring is active" : "Paused"} />{/snippet}
            </Card>
            <div class="flex flex-wrap justify-end gap-3">
                <Button variant="secondary" type="button" onclick={finishCreate}>Done</Button>
                <Button type="button" onclick={() => goto(`/monitorings/${createdMonitoringId}`)}>View website details</Button>
            </div>
        </div>
    {:else if createForm}
        <MonitoringForm options={createForm} action="/api/monitorings" method="POST" presentation="first-website" onSuccess={handleCreateSuccess} onCancel={() => (createOpen = false)} />
    {/if}
</Dialog>
