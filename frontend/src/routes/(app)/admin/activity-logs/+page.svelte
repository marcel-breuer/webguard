<script lang="ts">
    import { appRoutes } from "$lib/routes";
    import { formatDateTime, formatNumber } from "$lib/i18n/format";
    import DataTable from "$lib/components/DataTable.svelte";
    import Input from "$lib/components/Input.svelte";
    import Pagination from "$lib/components/Pagination.svelte";
    import Select from "$lib/components/Select.svelte";
    import type { AdminActivityLog, Paginated } from "$lib/api/admin";

    type SortKey = "description" | "event" | "created_at";
    type Direction = "asc" | "desc";
    interface Props { data: { filters: { direction: Direction; event: string; page: number; perPage: number; search: string; sort: SortKey }; logs: { data: Paginated<AdminActivityLog>; options: { events: string[] } } }; }
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
    <form class="mb-5 grid gap-4 rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-wg-surface sm:grid-cols-[minmax(0,1fr)_14rem_8rem_auto] sm:items-end" method="GET"><label class="grid gap-2 text-sm font-bold"><span>Search</span><Input name="search" type="search" value={filters.search} placeholder="Search activity logs" /></label><label class="grid gap-2 text-sm font-bold"><span>Event</span><Select name="event" value={filters.event}><option value="">All events</option>{#each data.logs.options.events as event}<option value={event}>{event}</option>{/each}</Select></label><label class="grid gap-2 text-sm font-bold"><span>Rows</span><Select name="per_page" value={String(filters.perPage)}><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></Select></label><div class="flex gap-3"><button class="inline-flex min-h-11 items-center justify-center rounded-md border border-transparent bg-wg-accent px-4 py-2.5 text-sm font-semibold tracking-[0.035em] text-wg-accent-contrast transition hover:bg-wg-accent-strong" type="submit">Apply</button><a class="inline-flex min-h-11 items-center justify-center rounded-md border border-wg-border px-4 py-2.5 text-sm font-semibold tracking-[0.035em] text-wg-text no-underline transition hover:border-wg-focus hover:bg-wg-surface-muted" href={appRoutes.adminActivityLogs}>Reset</a></div><input name="sort" type="hidden" value={filters.sort} /><input name="direction" type="hidden" value={filters.direction} /></form>
    {#if logs.items.length === 0}<p class="rounded-2xl border border-wg-border bg-wg-surface p-6 text-wg-text-muted">No activity matches the selected filters.</p>{:else}<DataTable caption="Activity logs"><thead><tr><th>Actor</th><th><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("description")}>Description{sortLabel("description")}</a></th><th><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("event")}>Event{sortLabel("event")}</a></th><th>Subject</th><th>Changes</th><th><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("created_at")}>Time{sortLabel("created_at")}</a></th></tr></thead><tbody>{#each logs.items as log (log.id)}<tr><td>{log.actor ?? "System"}</td><td>{log.description}</td><td>{log.event ?? log.log_name ?? "—"}</td><td>{log.subject_id ?? "—"}</td><td class="min-w-56"><details>{#if Object.keys(log.changes).length > 0}<summary class="cursor-pointer font-semibold text-wg-accent hover:text-wg-accent-strong">Show changes</summary><pre class="mt-3 max-h-72 overflow-auto rounded-lg bg-wg-surface-muted p-3 text-xs leading-5 text-wg-text">{changes(log)}</pre>{:else}<span class="text-wg-text-muted">No changes</span>{/if}</details></td><td class="whitespace-nowrap">{date(log.created_at)}</td></tr>{/each}</tbody></DataTable><div class="mt-4 flex flex-col gap-3 text-sm text-wg-text-muted sm:flex-row sm:items-center sm:justify-between"><p>Showing {formatNumber(logs.items.length)} of {formatNumber(logs.pagination.total)} entries</p><Pagination page={logs.pagination.current_page} pages={logs.pagination.last_page} href={(page) => href({ page })} /></div>{/if}
</main>
