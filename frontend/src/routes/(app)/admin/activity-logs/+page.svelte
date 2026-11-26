<script lang="ts">
    import * as Collapsible from "#lib/components/ui/collapsible/index.ts";
    import { Card as GlassCard } from "#lib/components/ui/card/index.ts";
    import { Field as FilterField, FieldLabel } from "#lib/components/ui/field/index.ts";
    import { Button as ActionLink } from "#lib/components/ui/button/index.ts";
    import * as Table from "#lib/components/ui/table/index.ts";
    import { appRoutes } from "#lib/routes.ts";
    import { formatDateTime, formatNumber } from "#lib/i18n/format.ts";
    import Button from "#lib/components/Button.svelte";
    import DataTable from "#lib/components/DataTable.svelte";
    import Input from "#lib/components/Input.svelte";
    import Pagination from "#lib/components/Pagination.svelte";
    import Select from "#lib/components/Select.svelte";
    import type { AdminActivityLog, Paginated } from "#lib/api/admin.ts";

    type SortKey = "description" | "event" | "created_at";
    type Direction = "asc" | "desc";
    interface Props { data: { filters: { direction: Direction; event: string; page: number; perPage: number; search: string; sort: SortKey }; logs: { data: Paginated<AdminActivityLog>; options: { events: string[] } } }; }
    const filterId = $props.id();
    let { data }: Props = $props();
    const logs = $derived(data.logs.data);
    const filters = $derived(data.filters);

    function date(value: string | null): string { return formatDateTime(value, "—"); }
    function changes(log: AdminActivityLog): string { return JSON.stringify(log.changes, null, 2); }
    function href(overrides: Partial<{ direction: Direction; event: string; page: number; perPage: number; search: string; sort: SortKey }> = {}): string {
        const values = { direction: filters.direction, event: filters.event, page: filters.page, perPage: filters.perPage, search: filters.search, sort: filters.sort, ...overrides };
        const params = new URLSearchParams();
        if (values.search !== "") params.set("search", values.search);
        if (values.event !== "") params.set("event", values.event);
        if (values.perPage !== 25) params.set("per_page", String(values.perPage));
        if (values.sort !== "created_at") params.set("sort", values.sort);
        if (values.direction !== "desc") params.set("direction", values.direction);
        if (values.page > 1) params.set("page", String(values.page));
        return params.size > 0 ? `${appRoutes.adminActivityLogs}?${params}` : appRoutes.adminActivityLogs;
    }
    function sortHref(sort: SortKey): string { return href({ direction: filters.sort === sort && filters.direction === "asc" ? "desc" : "asc", page: 1, sort }); }
    function sortLabel(sort: SortKey): string { return filters.sort === sort ? filters.direction === "asc" ? " ↑" : " ↓" : ""; }
</script>

<svelte:head><title>Activity logs | WebGuard</title></svelte:head>
<main class="mx-auto w-[min(78rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8"><p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Administration</p><h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Activity logs</h1><p class="mt-3 leading-6 text-wg-text-muted">Review privileged and operational events for auditability.</p></header>
    <GlassCard class="mb-5 overflow-visible p-5">
        <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem_8rem_auto] sm:items-end" method="GET">
            <FilterField>
                <FieldLabel for={`${filterId}-search`} class="font-bold">Search</FieldLabel>
                <Input id={`${filterId}-search`} name="search" type="search" value={filters.search} placeholder="Search activity logs" />
            </FilterField>
            <FilterField>
                <FieldLabel for={`${filterId}-event`} class="font-bold">Event</FieldLabel>
                <Select id={`${filterId}-event`} name="event" value={filters.event}><option value="">All events</option>{#each data.logs.options.events as event}<option value={event}>{event}</option>{/each}</Select>
            </FilterField>
            <FilterField>
                <FieldLabel for={`${filterId}-per_page`} class="font-bold">Rows</FieldLabel>
                <Select id={`${filterId}-per_page`} name="per_page" value={String(filters.perPage)}><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></Select>
            </FilterField>
            <div class="flex gap-3"><Button type="submit">Apply</Button><ActionLink variant="outline" class="no-underline" href={appRoutes.adminActivityLogs}>Reset</ActionLink></div>
            <input name="sort" type="hidden" value={filters.sort} /><input name="direction" type="hidden" value={filters.direction} />
        </form>
    </GlassCard>
    {#if logs.items.length === 0}<GlassCard class="p-6"><p class="text-wg-text-muted">No activity matches the selected filters.</p></GlassCard>{:else}<DataTable caption="Activity logs"><Table.Header><Table.Row><Table.Head>Actor</Table.Head><Table.Head><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("description")}>Description{sortLabel("description")}</a></Table.Head><Table.Head><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("event")}>Event{sortLabel("event")}</a></Table.Head><Table.Head>Subject</Table.Head><Table.Head>Changes</Table.Head><Table.Head><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("created_at")}>Time{sortLabel("created_at")}</a></Table.Head></Table.Row></Table.Header><Table.Body>{#each logs.items as log (log.id)}<Table.Row><Table.Cell>{log.actor ?? "System"}</Table.Cell><Table.Cell>{log.description}</Table.Cell><Table.Cell>{log.event ?? log.log_name ?? "—"}</Table.Cell><Table.Cell>{log.subject_id ?? "—"}</Table.Cell><Table.Cell class="min-w-56">{#if Object.keys(log.changes).length > 0}<Collapsible.Root><Collapsible.Trigger class="cursor-pointer font-semibold text-wg-accent hover:text-wg-accent-strong">Show changes</Collapsible.Trigger><Collapsible.Content><pre class="mt-3 max-h-72 overflow-auto rounded-lg bg-wg-surface-muted p-3 text-xs leading-5 text-wg-text">{changes(log)}</pre></Collapsible.Content></Collapsible.Root>{:else}<span class="text-wg-text-muted">No changes</span>{/if}</Table.Cell><Table.Cell class="whitespace-nowrap">{date(log.created_at)}</Table.Cell></Table.Row>{/each}</Table.Body></DataTable><div class="mt-4 flex flex-col gap-3 text-sm text-wg-text-muted sm:flex-row sm:items-center sm:justify-between"><p>Showing {formatNumber(logs.items.length)} of {formatNumber(logs.pagination.total)} entries</p><Pagination page={logs.pagination.current_page} pages={logs.pagination.last_page} href={(page) => href({ page })} /></div>{/if}
</main>
