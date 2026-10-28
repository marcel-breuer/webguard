import { error } from "@sveltejs/kit";
import type { AdminServerInstance, Paginated } from "#lib/api/admin.ts";

const perPageOptions = new Set([10, 25, 50, 100]);
const sortOptions = new Set(["display_name", "code", "country_code", "ip_address", "is_active", "created_at"]);
const directionOptions = new Set(["asc", "desc"]);
const activeOptions = new Set(["yes", "no"]);

export async function load({ fetch, url }) {
    const search = url.searchParams.get("search")?.trim() ?? "";
    const active = url.searchParams.get("active") ?? "";
    const perPage = Number(url.searchParams.get("per_page") ?? 20);
    const sort = url.searchParams.get("sort") ?? "code";
    const direction = url.searchParams.get("direction") ?? "asc";
    const page = Number(url.searchParams.get("page") ?? 1);
    const params = new URLSearchParams();

    if (search !== "") params.set("search", search);
    if (activeOptions.has(active)) params.set("active", active);
    if (perPageOptions.has(perPage) && perPage !== 20) params.set("per_page", String(perPage));
    if (sortOptions.has(sort) && sort !== "code") params.set("sort", sort);
    if (directionOptions.has(direction) && direction !== "asc") params.set("direction", direction);
    if (Number.isInteger(page) && page > 1) params.set("page", String(page));

    const response = await fetch(`/api/admin/server-instances${params.size > 0 ? `?${params}` : ""}`, { headers: { Accept: "application/json" } });
    if (!response.ok) error(response.status, "Server instances could not be loaded.");

    return {
        query: { active: activeOptions.has(active) ? active : "", direction: directionOptions.has(direction) ? direction as "asc" | "desc" : "asc", page: Number.isInteger(page) && page > 0 ? page : 1, perPage: perPageOptions.has(perPage) ? perPage : 20, search, sort: sortOptions.has(sort) ? sort : "code" },
        instances: await response.json() as { data: Paginated<AdminServerInstance> },
    };
}
