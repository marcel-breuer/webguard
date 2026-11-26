<script lang="ts">
    import { EmptyDescription } from "#lib/components/ui/empty/index.ts";
    import { Button as LinkButton } from "#lib/components/ui/button/index.ts";
    import { Card as Surface } from "#lib/components/ui/card/index.ts";
    import { Field, FieldGroup, FieldLabel } from "#lib/components/ui/field/index.ts";
    import * as Table from "#lib/components/ui/table/index.ts";
    import Button from "#lib/components/Button.svelte";
    import { formatDateTime, formatNumber } from "#lib/i18n/format.ts";
    import Card from "#lib/components/Card.svelte";
    import EmptyState from "#lib/components/EmptyState.svelte";
    import Input from "#lib/components/Input.svelte";
    import Pagination from "#lib/components/Pagination.svelte";
    import Select from "#lib/components/Select.svelte";
    import StatusBadge, { type StatusTone } from "#lib/components/StatusBadge.svelte";
    import type { AnalyticsDistribution, IncidentAnalyticsItem, IncidentAnalyticsResponse, OperationalSummary } from "#lib/api/incidents.ts";

    interface Props { data: { analytics: IncidentAnalyticsResponse }; }
    let { data }: Props = $props();
    const analytics = $derived(data.analytics.data);
    const pagination = $derived(data.analytics.meta.incident_pagination);
    const trendPoints = $derived(analytics.trend.points.map((point) => `${point.x},${point.y}`).join(" "));

    function stateTone(state: OperationalSummary["state"]): StatusTone {
        return state === "healthy" ? "healthy" : state === "degraded" ? "danger" : state === "attention" ? "degraded" : "neutral";
    }

    function stateLabel(state: OperationalSummary["state"]): string {
        return state === "healthy" ? "Healthy" : state === "degraded" ? "Degraded" : state === "attention" ? "Needs attention" : "No data";
    }

    function dateTime(value: string | null): string {
        return formatDateTime(value, "Ongoing");
    }

    function duration(incident: IncidentAnalyticsItem): string {
        if (incident.duration_minutes === null) return "Ongoing";
        if (incident.duration_minutes < 60) return `${formatNumber(incident.duration_minutes)} min`;

        const hours = Math.floor(incident.duration_minutes / 60);
        const minutes = incident.duration_minutes % 60;

        return minutes === 0 ? `${formatNumber(hours)} h` : `${formatNumber(hours)} h ${formatNumber(minutes)} min`;
    }

    function paginationHref(page: number): string {
        const params = new URLSearchParams();
        const filters = analytics.filters;

        if (filters.days !== 90) params.set("days", String(filters.days));
        if (filters.incident_type) params.set("incident_type", filters.incident_type);
        if (filters.severity) params.set("severity", filters.severity);
        if (filters.customer_impact) params.set("customer_impact", filters.customer_impact);
        if (filters.affected_service) params.set("affected_service", filters.affected_service);
        if (filters.sort !== "down_at") params.set("sort", filters.sort);
        if (filters.direction !== "desc") params.set("direction", filters.direction);
        if (page > 1) params.set("page", String(page));

        const query = params.toString();

        return query ? `/incidents/analytics?${query}` : "/incidents/analytics";
    }

    function sortHref(sort: "status" | "affected_service" | "down_at" | "up_at"): string {
        const params = new URLSearchParams();
        const filters = analytics.filters;
        const direction = filters.sort === sort && filters.direction === "asc" ? "desc" : "asc";

        if (filters.days !== 90) params.set("days", String(filters.days));
        if (filters.incident_type) params.set("incident_type", filters.incident_type);
        if (filters.severity) params.set("severity", filters.severity);
        if (filters.customer_impact) params.set("customer_impact", filters.customer_impact);
        if (filters.affected_service) params.set("affected_service", filters.affected_service);
        if (sort !== "down_at") params.set("sort", sort);
        if (direction !== "desc") params.set("direction", direction);

        return params.size > 0 ? `/incidents/analytics?${params}` : "/incidents/analytics";
    }

    function sortLabel(sort: "status" | "affected_service" | "down_at" | "up_at"): string {
        if (analytics.filters.sort !== sort) return "";

        return analytics.filters.direction === "asc" ? " ↑" : " ↓";
    }
</script>

<svelte:head><title>Service operations | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(76rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8 max-w-3xl">
        <p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Operations</p>
        <h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Service operations</h1>
        <p class="mt-3 leading-6 text-wg-text-muted">Keep system health, monitoring groups, status pages, and incident patterns in one operational view.</p>
    </header>

    <Surface as="section" class="p-5 sm:p-7" aria-labelledby="health-heading">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div><div class="flex items-center gap-3"><StatusBadge tone={stateTone(analytics.overview.overall_state)} label={stateLabel(analytics.overview.overall_state)} /><h2 id="health-heading" class="text-xl font-bold">Service health</h2></div><p class="mt-2 text-sm text-wg-text-muted">Updated with the latest monitoring results.</p></div>
            <dl class="grid grid-cols-2 gap-x-7 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                {#each [["total", "Monitorings"], ["healthy", "Healthy"], ["down", "Down"], ["unknown", "Unknown"], ["paused", "Paused"], ["maintenance", "Maintenance"]] as [key, label]}
                    <div><dt class="text-wg-text-muted">{label}</dt><dd class="mt-1 text-lg font-extrabold">{formatNumber(analytics.overview.summary[key as keyof typeof analytics.overview.summary])}</dd></div>
                {/each}
            </dl>
        </div>
    </Surface>

    <section class="mt-6 grid gap-6 xl:grid-cols-2">
        <Card title="Monitoring groups" description="Operational health across your reusable monitoring groups.">
            {#if analytics.groups.length === 0}<EmptyDescription class="text-sm text-wg-text-muted">No monitoring groups yet.</EmptyDescription>{:else}<div class="divide-y divide-wg-border">{#each analytics.groups as group (group.id)}<LinkButton variant="ghost" class="flex min-h-0 justify-between gap-4 rounded-none border-0 px-0 py-3 text-base leading-normal font-normal tracking-normal text-wg-text no-underline transition hover:bg-transparent hover:text-wg-accent active:translate-y-0" href={`/monitorings?group_id=${group.id}`}><div class="min-w-0"><p class="truncate font-bold">{group.name}</p><p class="mt-1 text-sm text-wg-text-muted">{formatNumber(group.monitoring_count)} monitorings · {formatNumber(group.healthy)} healthy{group.down > 0 ? ` · ${formatNumber(group.down)} down` : ""}</p></div><StatusBadge tone={stateTone(group.state)} label={stateLabel(group.state)} /></LinkButton>{/each}</div>{/if}
        </Card>
        <Card title="Status pages" description="Publication and component health at a glance.">
            {#if analytics.status_pages.length === 0}<EmptyDescription class="text-sm text-wg-text-muted">No status pages yet.</EmptyDescription>{:else}<div class="divide-y divide-wg-border">{#each analytics.status_pages as statusPage (statusPage.id)}<div class="flex items-center justify-between gap-4 py-3"><div class="min-w-0"><a class="truncate font-bold text-wg-text no-underline hover:text-wg-accent" href={`/status-pages/${statusPage.id}`}>{statusPage.name}</a><p class="mt-1 text-sm text-wg-text-muted">{formatNumber(statusPage.component_count)} components · {formatNumber(statusPage.total)} monitorings</p></div><div class="flex shrink-0 items-center gap-2"><StatusBadge tone={statusPage.is_public ? "healthy" : "paused"} label={statusPage.is_public ? "Public" : "Private"} /><LinkButton variant="link" class="min-h-0 border-0 p-0 font-bold tracking-normal text-wg-accent no-underline" href={`/status-pages/${statusPage.id}`}>Open</LinkButton></div></div>{/each}</div>{/if}
        </Card>
    </section>

    <section class="mt-8" aria-labelledby="incidents-heading">
        <div class="mb-5"><p class="text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Analytics</p><h2 id="incidents-heading" class="mt-1 text-2xl font-bold">Incidents</h2><p class="mt-2 text-sm leading-6 text-wg-text-muted">Incident count includes incidents opened in the selected period. MTTR is the average time between down and recovery for resolved incidents.</p></div>
        <Card title="Filter incidents" description="Narrow the operational view without a separate submit step.">
            <form method="GET" action="/incidents/analytics">
                <FieldGroup class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field><FieldLabel for="incidents-days" class="font-bold">Period</FieldLabel><Select id="incidents-days" name="days" value={String(analytics.filters.days)}><option value="30">Last 30 days</option><option value="90">Last 90 days</option><option value="365">Last 365 days</option></Select></Field>
                    <Field><FieldLabel for="incidents-incident_type" class="font-bold">Type</FieldLabel><Select id="incidents-incident_type" name="incident_type"><option value="">All</option>{#each analytics.filter_options.incident_types as option}<option value={option.value} selected={analytics.filters.incident_type === option.value}>{option.label}</option>{/each}</Select></Field>
                    <Field><FieldLabel for="incidents-severity" class="font-bold">Severity</FieldLabel><Select id="incidents-severity" name="severity"><option value="">All</option>{#each analytics.filter_options.severities as option}<option value={option.value} selected={analytics.filters.severity === option.value}>{option.label}</option>{/each}</Select></Field>
                    <Field><FieldLabel for="incidents-customer_impact" class="font-bold">Customer impact</FieldLabel><Select id="incidents-customer_impact" name="customer_impact"><option value="">All</option>{#each analytics.filter_options.customer_impacts as option}<option value={option.value} selected={analytics.filters.customer_impact === option.value}>{option.label}</option>{/each}</Select></Field>
                    <Field class="sm:col-span-2 lg:col-span-3"><FieldLabel for="incidents-affected_service" class="font-bold">Affected service</FieldLabel><Input id="incidents-affected_service" name="affected_service" value={analytics.filters.affected_service ?? ""} type="search" /></Field>
                    <div class="flex items-end"><Button class="w-full" type="submit">Apply filters</Button></div>
                </FieldGroup>
                <input name="sort" type="hidden" value={analytics.filters.sort} /><input name="direction" type="hidden" value={analytics.filters.direction} />
            </form>
        </Card>

        <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Card title="Incidents"><p class="text-3xl font-extrabold">{formatNumber(analytics.metrics.total)}</p></Card><Card title="Resolved"><p class="text-3xl font-extrabold text-green-700 dark:text-green-300">{formatNumber(analytics.metrics.resolved)}</p></Card><Card title="Open incidents"><p class="text-3xl font-extrabold text-wg-danger">{formatNumber(analytics.metrics.open)}</p></Card><Card title="Average MTTR"><p class="text-3xl font-extrabold">{analytics.metrics.mttr_minutes === null ? "n/a" : `${formatNumber(analytics.metrics.mttr_minutes)} min`}</p></Card></dl>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.7fr_1fr]"><Card title="Incidents over time" description={`Last ${formatNumber(analytics.filters.days)} days`}>
            {#if analytics.metrics.total === 0}<EmptyDescription class="text-sm text-wg-text-muted">No incidents were recorded in the selected period.</EmptyDescription>{:else}<svg class="h-56 w-full overflow-visible text-wg-accent" viewBox="0 0 100 86" role="img" aria-label="Incidents over time"><line class="stroke-wg-border" x1="0" y1="20" x2="100" y2="20" stroke-dasharray="1 2" /><line class="stroke-wg-border" x1="0" y1="49" x2="100" y2="49" stroke-dasharray="1 2" /><line class="stroke-wg-border" x1="0" y1="78" x2="100" y2="78" /><polyline class="fill-wg-accent/10 stroke-none" points={`0,78 ${trendPoints} 100,78`} /><polyline class="fill-none stroke-current" points={trendPoints} stroke-width="1.3" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" />{#each analytics.trend.points as point}<circle class="fill-current" cx={point.x} cy={point.y} r="1.5" />{/each}</svg><div class="mt-2 flex justify-between gap-2 text-xs text-wg-text-muted">{#each analytics.trend.points as point}<span>{point.label}</span>{/each}</div>{/if}
        </Card><Card title="Recurring services" description="Services with more than one incident in this period.">{#if analytics.repeat_services.length === 0}<EmptyDescription class="text-sm text-wg-text-muted">No recurring services in this period.</EmptyDescription>{:else}<dl class="divide-y divide-wg-border">{#each analytics.repeat_services as service}<div class="flex items-center justify-between gap-3 py-3 text-sm"><dt class="truncate text-wg-text-muted">{service.service}</dt><dd class="font-extrabold">{formatNumber(service.count)}</dd></div>{/each}</dl>{/if}</Card></div>

        <div class="mt-6 grid gap-4 md:grid-cols-3">{@render DistributionCard("By type", analytics.distributions.by_type)}{@render DistributionCard("By severity", analytics.distributions.by_severity)}{@render DistributionCard("By customer impact", analytics.distributions.by_impact)}</div>

        <section class="mt-6"><Card title="Incidents in selected period" description={pagination.total === 0 ? "No incidents match the selected filters." : `${formatNumber(pagination.from ?? 0)}–${formatNumber(pagination.to ?? 0)} of ${formatNumber(pagination.total)} incidents`}>
            {#if analytics.incidents.length === 0}<EmptyState title="No incidents match the selected filters" description="Try a broader period or remove one of the filters." />{:else}<Table.Root class="w-full min-w-190 border-collapse text-left text-sm"><Table.Header class="border-b border-wg-border text-wg-text-muted"><Table.Row><Table.Head class="px-3 py-3 font-bold"><a class="text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("status")}>Status{sortLabel("status")}</a></Table.Head><Table.Head class="px-3 py-3 font-bold">Monitoring</Table.Head><Table.Head class="px-3 py-3 font-bold"><a class="text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("affected_service")}>Affected service{sortLabel("affected_service")}</a></Table.Head><Table.Head class="px-3 py-3 font-bold"><a class="text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("down_at")}>Started{sortLabel("down_at")}</a></Table.Head><Table.Head class="px-3 py-3 font-bold"><a class="text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("up_at")}>Resolved{sortLabel("up_at")}</a></Table.Head><Table.Head class="px-3 py-3 font-bold">Duration</Table.Head></Table.Row></Table.Header><Table.Body class="divide-y divide-wg-border">{#each analytics.incidents as incident (incident.id)}<Table.Row><Table.Cell class="px-3 py-4"><StatusBadge tone={incident.status === "open" ? "danger" : "healthy"} label={incident.status === "open" ? "Open incident" : "Resolved incident"} /></Table.Cell><Table.Cell class="px-3 py-4"><a class="font-bold text-wg-text no-underline hover:text-wg-accent" href={`/monitorings/${incident.monitoring_id}`}>{incident.monitoring_name}</a></Table.Cell><Table.Cell class="px-3 py-4 text-wg-text-muted">{incident.affected_service}</Table.Cell><Table.Cell class="px-3 py-4 text-wg-text-muted">{dateTime(incident.down_at)}</Table.Cell><Table.Cell class="px-3 py-4 text-wg-text-muted">{dateTime(incident.up_at)}</Table.Cell><Table.Cell class="px-3 py-4 font-bold">{duration(incident)}</Table.Cell></Table.Row>{/each}</Table.Body></Table.Root><div class="mt-5"><Pagination page={pagination.current_page} pages={pagination.last_page} href={paginationHref} /></div>{/if}
        </Card></section>
    </section>
</main>

{#snippet DistributionCard(title: string, items: AnalyticsDistribution[])}
    <Card {title}>{#if items.length === 0}<EmptyDescription class="text-sm text-wg-text-muted">No incidents match the selected filters.</EmptyDescription>{:else}<dl class="divide-y divide-wg-border">{#each items as item}<div class="flex items-center justify-between gap-3 py-2 text-sm"><dt class="text-wg-text-muted">{item.label}</dt><dd class="font-extrabold">{formatNumber(item.count)}</dd></div>{/each}</dl>{/if}</Card>
{/snippet}
