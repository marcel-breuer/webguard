import { error } from "@sveltejs/kit";
import type { DashboardResponse } from "#lib/api/monitoring.ts";

export async function load({ fetch, url }) {
    const servicePage = Number(url.searchParams.get("service_page") ?? "1");
    const params = new URLSearchParams();

    if (Number.isInteger(servicePage) && servicePage > 1 && servicePage <= 100) {
        params.set("service_page", String(servicePage));
    }

    const query = params.size > 0 ? `?${params}` : "";
    const response = await fetch(`/api/dashboard${query}`, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    });

    if (!response.ok) {
        error(response.status, "Dashboard data could not be loaded.");
    }

    return {
        dashboard: await response.json() as DashboardResponse,
    };
}
