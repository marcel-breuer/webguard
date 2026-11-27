<script lang="ts">
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
    import type { AdminApiLog, AdminApiLogUser, Paginated } from "#lib/api/admin.ts";

    type SortKey = "created_at" | "user_email" | "route";
    type Direction = "asc" | "desc";

    interface Props {
        data: {
            filters: { direction: string; page: number; perPage: number; search: string; sort: string; userId: string };
            logs: { data: Paginated<AdminApiLog>; options: { users: AdminApiLogUser[] } };
        };
    }

    const filterId = $props.id();
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
        <p class="m-0 text-sm font-semibold text-wg-text-muted">Administration</p>
        <h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">API access</h1>
        <p class="mt-3 max-w-2xl leading-6 text-wg-text-muted">Review recent authenticated API usage without exposing credentials.</p>
    </header>

    <GlassCard class="mb-5 overflow-visible p-5">
        <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem_8rem_auto] sm:items-end" method="GET">

            <FilterField>
                <FieldLabel for={`${filterId}-search`} class="font-bold">Search</FieldLabel>
                <Input id={`${filterId}-search`} name="search" type="search" value={filters.search} placeholder="Search route or user email" />
            </FilterField>

            <FilterField>
                <FieldLabel for={`${filterId}-user_id`} class="font-bold">User</FieldLabel>
                <Select id={`${filterId}-user_id`} name="user_id" value={filters.userId}><option value="">All users</option>{#each data.logs.options.users as user}<option value={user.id}>{user.email}</option>{/each}</Select>
            </FilterField>

            <FilterField>
                <FieldLabel for={`${filterId}-per_page`} class="font-bold">Rows</FieldLabel>
                <Select id={`${filterId}-per_page`} name="per_page" value={String(filters.perPage)}><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></Select>
            </FilterField>

            <div class="flex flex-col gap-2 sm:flex-row sm:gap-3"><Button type="submit">Apply</Button><ActionLink variant="outline" class="no-underline" href={appRoutes.adminApi}>Reset</ActionLink></div>

            <input name="sort" type="hidden" value={filters.sort} /><input name="direction" type="hidden" value={filters.direction} />

        </form>
    </GlassCard>

    {#if logs.items.length === 0}
        <GlassCard class="p-6"><p class="text-wg-text-muted">No API activity matches the selected filters.</p></GlassCard>
    {:else}
        <DataTable caption="API usage">
            <Table.Header><Table.Row><Table.Head><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("created_at")}>Time{sortLabel("created_at")}</a></Table.Head><Table.Head><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("user_email")}>User{sortLabel("user_email")}</a></Table.Head><Table.Head><a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref("route")}>Endpoint{sortLabel("route")}</a></Table.Head></Table.Row></Table.Header>
            <Table.Body>{#each logs.items as log (log.id)}<Table.Row><Table.Cell class="whitespace-nowrap">{date(log.created_at)}</Table.Cell><Table.Cell>{log.user_email ?? "Deleted user"}</Table.Cell><Table.Cell class="max-w-150 truncate" title={log.route ?? ""}>{log.route ?? "—"}</Table.Cell></Table.Row>{/each}</Table.Body>
        </DataTable>
        <div class="mt-4 flex flex-col gap-3 text-sm text-wg-text-muted sm:flex-row sm:items-center sm:justify-between"><p>Showing {formatNumber(logs.items.length)} of {formatNumber(logs.pagination.total)} entries</p><Pagination page={logs.pagination.current_page} pages={logs.pagination.last_page} href={paginationHref} /></div>
    {/if}
</main>
