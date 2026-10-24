<script lang="ts">
    import { appRoutes } from "$lib/routes";
    import { formatDateTime, formatNumber } from "$lib/i18n/format";
    import Button from "$lib/components/Button.svelte";
    import DataTable from "$lib/components/DataTable.svelte";
    import Input from "$lib/components/Input.svelte";
    import Pagination from "$lib/components/Pagination.svelte";
    import Select from "$lib/components/Select.svelte";
    import type { AdminApiLog, AdminApiLogUser, Paginated } from "$lib/api/admin";

    type SortKey = "created_at" | "user_email" | "route";
    type Direction = "asc" | "desc";

    interface Props {
        data: {
            filters: { direction: string; page: number; perPage: number; search: string; sort: string; userId: string };
            logs: { data: Paginated<AdminApiLog>; options: { users: AdminApiLogUser[] } };
        };
    }

    let { data }: Props = $props();
    const logs = $derived(data.logs.data);
    const filters = $derived(data.filters);

    function date(value: string | null): string {
        return formatDateTime(value, "—");
    }

    function href(overrides: Partial<{ direction: Direction; page: number; perPage: number; search: string; sort: SortKey; userId: string }> = {}): string {
        const values = {
            direction: filters.direction as Direction,
            page: filters.page,
            perPage: filters.perPage,
            search: filters.search,
            sort: filters.sort as SortKey,
            userId: filters.userId,
            ...overrides,
        };
        const params = new URLSearchParams();

        if (values.search !== "") params.set("search", values.search);
        if (values.userId !== "") params.set("user_id", values.userId);
        if (values.perPage !== 25) params.set("per_page", String(values.perPage));
        if (values.sort !== "created_at") params.set("sort", values.sort);
        if (values.direction !== "desc") params.set("direction", values.direction);
        if (values.page > 1) params.set("page", String(values.page));

        return params.size > 0 ? `${appRoutes.adminApi}?${params}` : appRoutes.adminApi;
    }

    function sortHref(sort: SortKey): string {
        const direction: Direction = filters.sort === sort && filters.direction === "asc" ? "desc" : "asc";

        return href({ direction, page: 1, sort });
    }

    function sortLabel(sort: SortKey): string {
        if (filters.sort !== sort) return "";

        return filters.direction === "asc" ? " ↑" : " ↓";
    }

    function paginationHref(page: number): string {
        return href({ page });
    }
</script>

<svelte:head><title>API access | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(78rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8">
        <p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Administration</p>
        <h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">API access</h1>
        <p class="mt-3 leading-6 text-wg-text-muted">Review recent authenticated API usage without exposing credentials.</p>
    </header>

    <form class="mb-5 grid gap-4 rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-wg-surface sm:grid-cols-[minmax(0,1fr)_14rem_8rem_auto] sm:items-end" method="GET">
        <label class="grid gap-2 text-sm font-bold"><span>Search</span><Input name="search" type="search" value={filters.search} placeholder="Search route or user email" /></label>
        <label class="grid gap-2 text-sm font-bold"><span>User</span><Select name="user_id" value={filters.userId}><option value="">All users</option>{#each data.logs.options.users as user}<option value={user.id}>{user.email}</option>{/each}</Select></label>
        <label class="grid gap-2 text-sm font-bold"><span>Rows</span><Select name="per_page" value={String(filters.perPage)}><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></Select></label>
        <div class="flex gap-3"><Button type="submit">Apply</Button><a class="inline-flex min-h-11 items-center justify-center rounded-md border border-wg-accent bg-transparent px-4 py-2.5 text-sm font-semibold tracking-[0.035em] text-wg-accent no-underline transition-[background-color,border-color,box-shadow,color,transform] duration-150 ease-out hover:bg-violet-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus dark:hover:bg-violet-950/40" href={appRoutes.adminApi}>Reset</a></div>
        <input name="sort" type="hidden" value={filters.sort} /><input name="direction" type="hidden" value={filters.direction} />
    </form>

    {#if logs.items.length === 0}
        <p class="rounded-2xl border border-wg-border bg-wg-surface p-6 text-wg-text-muted">No API activity matches the selected filters.</p>
    {:else}
        <DataTable caption="API usage">
            <thead><tr><th><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("created_at")}>Time{sortLabel("created_at")}</a></th><th><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("user_email")}>User{sortLabel("user_email")}</a></th><th><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("route")}>Endpoint{sortLabel("route")}</a></th></tr></thead>
            <tbody>{#each logs.items as log (log.id)}<tr><td class="whitespace-nowrap">{date(log.created_at)}</td><td>{log.user_email ?? "Deleted user"}</td><td class="max-w-150 truncate" title={log.route ?? ""}>{log.route ?? "—"}</td></tr>{/each}</tbody>
        </DataTable>
        <div class="mt-4 flex flex-col gap-3 text-sm text-wg-text-muted sm:flex-row sm:items-center sm:justify-between"><p>Showing {formatNumber(logs.items.length)} of {formatNumber(logs.pagination.total)} entries</p><Pagination page={logs.pagination.current_page} pages={logs.pagination.last_page} href={paginationHref} /></div>
    {/if}
</main>
