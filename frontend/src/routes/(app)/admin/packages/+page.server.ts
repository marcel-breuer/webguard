import { error } from "@sveltejs/kit";
import type { AdminPackage, Paginated } from "#lib/api/admin.ts";

const perPageOptions = new Set([10, 25, 50, 100]);
const sortOptions = new Set(["monitoring_limit", "price", "is_selectable", "created_at"]);
const directionOptions = new Set(["asc", "desc"]);
const selectableOptions = new Set(["yes", "no"]);

export async function load({ fetch, url }) {
    const search = url.searchParams.get("search")?.trim() ?? "";
    const selectable = url.searchParams.get("selectable") ?? "";
    const perPage = Number(url.searchParams.get("per_page") ?? 20);
    const sort = url.searchParams.get("sort") ?? "price";
    const direction = url.searchParams.get("direction") ?? "asc";
    const page = Number(url.searchParams.get("page") ?? 1);
    const params = new URLSearchParams();

    if (search !== "") params.set("search", search);
    if (selectableOptions.has(selectable)) params.set("selectable", selectable);
    if (perPageOptions.has(perPage) && perPage !== 20) params.set("per_page", String(perPage));
    if (sortOptions.has(sort) && sort !== "price") params.set("sort", sort);
    if (directionOptions.has(direction) && direction !== "asc") params.set("direction", direction);
    if (Number.isInteger(page) && page > 1) params.set("page", String(page));

    const response = await fetch(`/api/admin/packages${params.size > 0 ? `?${params}` : ""}`, { headers: { Accept: "application/json" } });
    if (!response.ok) error(response.status, "Packages could not be loaded.");

    return {
        query: { direction: directionOptions.has(direction) ? direction as "asc" | "desc" : "asc", page: Number.isInteger(page) && page > 0 ? page : 1, perPage: perPageOptions.has(perPage) ? perPage : 20, search, selectable: selectableOptions.has(selectable) ? selectable : "", sort: sortOptions.has(sort) ? sort : "price" },
        packages: await response.json() as { data: Paginated<AdminPackage> },
    };
}
