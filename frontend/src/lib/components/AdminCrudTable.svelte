<script lang="ts">
    import { invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "$lib/api/client";
    import type { Pagination } from "$lib/api/admin";
    import Button from "$lib/components/Button.svelte";
    import Checkbox from "$lib/components/Checkbox.svelte";
    import DataTable from "$lib/components/DataTable.svelte";
    import Dialog from "$lib/components/Dialog.svelte";
    import EmptyState from "$lib/components/EmptyState.svelte";
    import Field from "$lib/components/Field.svelte";
    import Input from "$lib/components/Input.svelte";
    import PaginationControls from "$lib/components/Pagination.svelte";
    import Select from "$lib/components/Select.svelte";
    import { formatNumber } from "$lib/i18n/format";

    type Item = Record<string, unknown>;
    type QueryValue = string | number;
    interface Column { key: string; label: string; sortKey?: string; format?: (value: unknown, item: Item) => string; }
    interface CrudField { name: string; label: string; type?: "text" | "email" | "password" | "number" | "select" | "checkbox"; required?: boolean; options?: Array<{ value: string; label: string }>; }
    interface TableFilter { name: string; label: string; value: string; options: Array<{ value: string; label: string }>; }
    interface TableQuery { search: string; perPage: number; sort: string; direction: "asc" | "desc"; [key: string]: QueryValue; }
    interface Props {
        title: string;
        description: string;
        singular: string;
        endpoint: string;
        pagePath: string;
        items: Item[];
        pagination: Pagination;
        query: TableQuery;
        columns: Column[];
        fields: CrudField[];
        tableFilters?: TableFilter[];
        createDefaults?: Item;
        itemLabel?: (item: Item) => string;
        extraActions?: (item: Item) => Array<{ label: string; endpoint: string }>;
    }

    let { title, description, singular, endpoint, pagePath, items, pagination, query, columns, fields, tableFilters = [], createDefaults = {}, itemLabel = (item) => String(item.id), extraActions = () => [] }: Props = $props();
    let dialogOpen = $state(false);
    let editing = $state<Item | null>(null);
    let error = $state("");
    let saving = $state(false);

    function openCreate(): void { editing = null; error = ""; dialogOpen = true; }
    function openEdit(item: Item): void { editing = item; error = ""; dialogOpen = true; }
    function value(item: Item | null, field: CrudField): string { const raw = item?.[field.name] ?? createDefaults[field.name] ?? ""; return String(raw); }
    function format(column: Column, item: Item): string {
        const value = item[column.key];

        if (column.format) return column.format(value, item);
        if (typeof value === "number") return formatNumber(value);

        return String(value ?? "—");
    }

    function href(overrides: Record<string, QueryValue> = {}): string {
        const values: Record<string, QueryValue> = { ...query, ...overrides };
        const params = new URLSearchParams();

        for (const [key, value] of Object.entries(values)) {
            if (value === "" || (key === "page" && value === 1)) continue;
            params.set(key === "perPage" ? "per_page" : key, String(value));
        }

        return params.size > 0 ? `${pagePath}?${params}` : pagePath;
    }

    function sortHref(sort: string): string {
        const direction: "asc" | "desc" = query.sort === sort && query.direction === "asc" ? "desc" : "asc";
        return href({ direction, page: 1, sort });
    }

    function sortLabel(sort: string): string {
        if (query.sort !== sort) return "";
        return query.direction === "asc" ? " ↑" : " ↓";
    }

    async function submit(event: SubmitEvent): Promise<void> {
        event.preventDefault();
        saving = true; error = "";
        const form = event.currentTarget as HTMLFormElement;
        const body = new FormData(form);
        for (const field of fields.filter((field) => field.type === "checkbox")) body.set(field.name, (form.elements.namedItem(field.name) as HTMLInputElement).checked ? "1" : "0");
        try {
            await requestFirstPartyApi(editing ? `${endpoint}/${editing.id}` : endpoint, { method: editing ? "PATCH" : "POST", body });
            dialogOpen = false; editing = null; await invalidateAll();
        } catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : `The ${singular} could not be saved.`; } finally { saving = false; }
    }
    async function remove(item: Item): Promise<void> {
        if (!globalThis.confirm(`Delete ${itemLabel(item)}? This cannot be undone.`)) return;
        error = "";
        try { await requestFirstPartyApi(`${endpoint}/${item.id}`, { method: "DELETE" }); await invalidateAll(); }
        catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : `The ${singular} could not be deleted.`; }
    }
    async function action(item: Item, mutation: { label: string; endpoint: string }): Promise<void> {
        error = "";
        try { await requestFirstPartyApi(mutation.endpoint, { method: "POST" }); await invalidateAll(); }
        catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : `The ${singular} could not be updated.`; }
    }
</script>

<section>
    <header class="mb-8 flex flex-col items-start justify-between gap-4 sm:flex-row"><div><p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Administration</p><h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">{title}</h1><p class="mt-3 leading-6 text-wg-text-muted">{description}</p></div><Button onclick={openCreate}>Create {singular}</Button></header>
    {#if error}<p class="mb-5 text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}

    <form class="mb-5 grid gap-4 rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-wg-surface sm:grid-cols-[minmax(0,1fr)_11rem_8rem_auto] sm:items-end" method="GET">
        <label class="grid gap-2 text-sm font-bold"><span>Search</span><Input name="search" type="search" value={query.search} placeholder={`Search ${title.toLowerCase()}`} /></label>
        {#each tableFilters as filter}<label class="grid gap-2 text-sm font-bold"><span>{filter.label}</span><Select name={filter.name} value={filter.value}><option value="">All {filter.label.toLowerCase()}</option>{#each filter.options as option}<option value={option.value}>{option.label}</option>{/each}</Select></label>{/each}
        <label class="grid gap-2 text-sm font-bold"><span>Rows</span><Select name="per_page" value={String(query.perPage)}><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></Select></label>
        <div class="flex gap-3"><Button type="submit">Apply</Button><a class="inline-flex min-h-11 items-center justify-center rounded-md border border-wg-accent bg-transparent px-4 py-2.5 text-sm font-semibold tracking-[0.035em] text-wg-accent no-underline transition-[background-color,border-color,box-shadow,color,transform] duration-150 ease-out hover:bg-violet-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus dark:hover:bg-violet-950/40" href={pagePath}>Reset</a></div>
        <input name="sort" type="hidden" value={query.sort} /><input name="direction" type="hidden" value={query.direction} />
    </form>

    {#if items.length === 0}<EmptyState title={`No ${title.toLowerCase()} match the selected filters`} description="Try a broader search or reset the table controls." />{:else}<DataTable caption={title}><thead><tr>{#each columns as column}<th>{#if column.sortKey}<a class="font-bold text-wg-text-muted no-underline hover:text-wg-accent" href={sortHref(column.sortKey)}>{column.label}{sortLabel(column.sortKey)}</a>{:else}{column.label}{/if}</th>{/each}<th>Actions</th></tr></thead><tbody>{#each items as item (String(item.id))}<tr>{#each columns as column}<td>{format(column, item)}</td>{/each}<td><div class="flex flex-wrap gap-2 md:flex-nowrap"><Button class="min-h-9 px-3 py-1.5 text-xs" variant="secondary" type="button" onclick={() => openEdit(item)}>Edit</Button>{#each extraActions(item) as mutation}<Button class="min-h-9 px-3 py-1.5 text-xs" variant="secondary" type="button" onclick={() => action(item, mutation)}>{mutation.label}</Button>{/each}<Button class="min-h-9 px-3 py-1.5 text-xs" variant="danger" type="button" onclick={() => remove(item)}>Delete</Button></div></td></tr>{/each}</tbody></DataTable>{/if}

    <div class="mt-4 flex flex-col gap-3 text-sm text-wg-text-muted sm:flex-row sm:items-center sm:justify-between"><p>Showing {formatNumber(items.length)} of {formatNumber(pagination.total)} entries</p><PaginationControls page={pagination.current_page} pages={pagination.last_page} href={(page) => href({ page })} /></div>
</section>

<Dialog bind:open={dialogOpen} title={editing ? `Edit ${singular}` : `Create ${singular}`}><form class="grid gap-4" onsubmit={submit} novalidate>{#each fields as field}<Field label={field.label} required={field.required}>{#if field.type === "select"}<Select name={field.name} value={value(editing, field)} required={field.required && !editing}>{#each field.options ?? [] as option}<option value={option.value}>{option.label}</option>{/each}</Select>{:else if field.type === "checkbox"}<Checkbox name={field.name} checked={value(editing, field) === "true" || value(editing, field) === "1"} />{:else}<Input name={field.name} type={field.type ?? "text"} value={value(editing, field)} required={field.required && (!editing || field.type !== "password")} />{/if}</Field>{/each}{#if error}<p class="text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}<Button type="submit" loading={saving}>{editing ? "Save changes" : `Create ${singular}`}</Button></form></Dialog>
