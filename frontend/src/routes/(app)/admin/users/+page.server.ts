import { error } from "@sveltejs/kit";
import type { AdminPackage, AdminUser, Paginated } from "#lib/api/admin.ts";

const perPageOptions = new Set([10, 25, 50, 100]);
const sortOptions = new Set(["name", "email", "role", "created_at"]);
const directionOptions = new Set(["asc", "desc"]);
const roleOptions = new Set(["admin", "regular", "demo"]);

export async function load({ fetch, url }) {
    const search = url.searchParams.get("search")?.trim() ?? "";
    const role = url.searchParams.get("role") ?? "";
    const perPage = Number(url.searchParams.get("per_page") ?? 20);
    const sort = url.searchParams.get("sort") ?? "created_at";
    const direction = url.searchParams.get("direction") ?? "desc";
    const page = Number(url.searchParams.get("page") ?? 1);
    const params = new URLSearchParams();

    if (search !== "") params.set("search", search);
    if (roleOptions.has(role)) params.set("role", role);
    if (perPageOptions.has(perPage) && perPage !== 20) params.set("per_page", String(perPage));
    if (sortOptions.has(sort) && sort !== "created_at") params.set("sort", sort);
    if (directionOptions.has(direction) && direction !== "desc") params.set("direction", direction);
    if (Number.isInteger(page) && page > 1) params.set("page", String(page));

    const response = await fetch(`/api/admin/users${params.size > 0 ? `?${params}` : ""}`, { headers: { Accept: "application/json" } });
    if (!response.ok) error(response.status, "Users could not be loaded.");

    return {
        query: { direction: directionOptions.has(direction) ? direction as "asc" | "desc" : "desc", page: Number.isInteger(page) && page > 0 ? page : 1, perPage: perPageOptions.has(perPage) ? perPage : 20, role: roleOptions.has(role) ? role : "", search, sort: sortOptions.has(sort) ? sort : "created_at" },
        users: await response.json() as { data: Paginated<AdminUser>; options: { packages: AdminPackage[]; roles: string[] } },
    };
}
