import { error } from "@sveltejs/kit";
import type { AdminApiLog, AdminApiLogUser, Paginated } from "#lib/api/admin.ts";

const defaults = { direction: "desc", page: 1, perPage: 25, search: "", sort: "created_at", userId: "" } as const;

export async function load({ fetch, url }) {
    const params = new URLSearchParams();
    const search = url.searchParams.get("search") ?? defaults.search;
    const userId = url.searchParams.get("user_id") ?? defaults.userId;
    const perPage = Number(url.searchParams.get("per_page") ?? defaults.perPage);
    const sort = url.searchParams.get("sort") ?? defaults.sort;
    const direction = url.searchParams.get("direction") ?? defaults.direction;
    const page = Number(url.searchParams.get("page") ?? defaults.page);

    if (search !== "") params.set("search", search);
    if (userId !== "") params.set("user_id", userId);
    if (perPage !== defaults.perPage) params.set("per_page", String(perPage));
    if (sort !== defaults.sort) params.set("sort", sort);
    if (direction !== defaults.direction) params.set("direction", direction);
    if (page > 1) params.set("page", String(page));

    const response = await fetch(`/api/admin/api-logs${params.size > 0 ? `?${params}` : ""}`, { headers: { Accept: "application/json" } });

    if (!response.ok) error(response.status, "API usage could not be loaded.");

    return {
        filters: { direction, page, perPage, search, sort, userId },
        logs: await response.json() as { data: Paginated<AdminApiLog>; options: { users: AdminApiLogUser[] } },
    };
}
