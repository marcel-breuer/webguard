<script lang="ts">
    import { appRoutes } from "$lib/routes";
    import AdminCrudTable from "$lib/components/AdminCrudTable.svelte";
    import { formatCurrency, formatNumber } from "$lib/i18n/format";

    interface Props { data: { query: { direction: "asc" | "desc"; page: number; perPage: number; search: string; selectable: string; sort: string }; packages: { data: { items: Array<Record<string, unknown>>; pagination: { current_page: number; last_page: number; total: number } } } }; }
    let { data }: Props = $props();
</script>

<svelte:head><title>Packages | WebGuard</title></svelte:head>
<main class="mx-auto w-[min(78rem,calc(100%_-_2rem))] py-6 sm:py-12"><AdminCrudTable title="Packages" description="Configure monitoring capacity and availability for subscription packages." singular="package" endpoint="/api/admin/packages" pagePath={appRoutes.adminPackages} items={data.packages.data.items} pagination={data.packages.data.pagination} query={data.query} tableFilters={[{ name: "selectable", label: "Availability", value: data.query.selectable, options: [{ value: "yes", label: "Selectable" }, { value: "no", label: "Hidden" }] }]} itemLabel={(item) => `${formatNumber(Number(item.monitoring_limit))} monitorings`} columns={[{ key: "monitoring_limit", label: "Monitoring limit", sortKey: "monitoring_limit" }, { key: "price", label: "Price", sortKey: "price", format: (value) => formatCurrency(Number(value), "EUR") }, { key: "is_selectable", label: "Selectable", sortKey: "is_selectable", format: (value) => value ? "Yes" : "No" }]} fields={[{ name: "monitoring_limit", label: "Monitoring limit", type: "number", required: true }, { name: "price", label: "Price", type: "number", required: true }, { name: "is_selectable", label: "Selectable", type: "checkbox" }]} createDefaults={{ is_selectable: true }} /></main>
