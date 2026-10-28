import { error } from "@sveltejs/kit";
import type { AdminActivityLog, Paginated } from "#lib/api/admin.ts";

const perPageOptions = new Set([10, 25, 50, 100]);
const sortOptions = new Set(["description", "event", "created_at"]);
const directionOptions = new Set(["asc", "desc"]);

export async function load({ fetch, url }) {
    const search = url.searchParams.get("search")?.trim() ?? "";
    const event = url.searchParams.get("event") ?? "";
    const perPage = Number(url.searchParams.get("per_page") ?? 25);
    const sort = url.searchParams.get("sort") ?? "created_at";
    const direction = url.searchParams.get("direction") ?? "desc";
    const page = Number(url.searchParams.get("page") ?? 1);
    const params = new URLSearchParams();

    if (search !== "") params.set("search", search);
    if (event !== "") params.set("event", event);
    if (perPageOptions.has(perPage) && perPage !== 25) params.set("per_page", String(perPage));
    if (sortOptions.has(sort) && sort !== "created_at") params.set("sort", sort);
    if (directionOptions.has(direction) && direction !== "desc") params.set("direction", direction);
    if (Number.isInteger(page) && page > 1) params.set("page", String(page));

    const response = await fetch(`/api/admin/activity-logs${params.size > 0 ? `?${params}` : ""}`, { headers: { Accept: "application/json" } });
    if (!response.ok) error(response.status, "Activity logs could not be loaded.");

    return {
        filters: { direction: directionOptions.has(direction) ? direction as "asc" | "desc" : "desc", event, page: Number.isInteger(page) && page > 0 ? page : 1, perPage: perPageOptions.has(perPage) ? perPage : 25, search, sort: sortOptions.has(sort) ? sort : "created_at" },
        logs: await response.json() as { data: Paginated<AdminActivityLog>; options: { events: string[] } },
    };
}
