import { error } from "@sveltejs/kit";
import type { DashboardResponse, MonitoringCardsResponse, MonitoringListResponse } from "#lib/api/monitoring.ts";

const lifecycleStatuses = new Set(["active", "paused"]);

function positiveInteger(value: string | null): number | null {
    const parsed = Number(value);

    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

export async function load({ fetch, url }) {
    const params = new URLSearchParams();
    const page = positiveInteger(url.searchParams.get("page"));
    const search = url.searchParams.get("search")?.trim() ?? "";
    const lifecycleStatus = url.searchParams.get("lifecycle_status");

    if (page !== null && page > 1) {
        params.set("page", String(page));
    }

    if (search !== "") {
        params.set("search", search.slice(0, 120));
    }

    if (lifecycleStatus !== null && lifecycleStatuses.has(lifecycleStatus)) {
        params.set("lifecycle_status", lifecycleStatus);
    }

    const query = params.size > 0 ? `?${params}` : "";
    const headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
    const [response, dashboardResponse] = await Promise.all([
        fetch(`/api/monitorings${query}`, { headers }),
        fetch("/api/dashboard", { headers }),
    ]);

    if (!response.ok) {
        error(response.status, "Monitorings could not be loaded.");
    }

    if (!dashboardResponse.ok) {
        error(dashboardResponse.status, "Monitoring summary could not be loaded.");
    }

    const monitorings = await response.json() as MonitoringListResponse;
    const monitoringIds = monitorings.data.map((monitoring) => monitoring.id);
    const cardParams = new URLSearchParams();

    for (const monitoringId of monitoringIds) {
        cardParams.append("ids[]", monitoringId);
    }

    const cardsResponse = monitoringIds.length === 0
        ? null
        : await fetch(`/api/monitorings/cards?${cardParams}`, { headers });

    return {
        filters: {
            lifecycleStatus: lifecycleStatus !== null && lifecycleStatuses.has(lifecycleStatus) ? lifecycleStatus : "",
            search,
        },
        monitorings,
        dashboard: await dashboardResponse.json() as DashboardResponse,
        cards: cardsResponse?.ok
            ? await cardsResponse.json() as MonitoringCardsResponse
            : { data: {}, summary: { attention: 0, healthy: 0, paused: 0, maintenance: 0 } },
    };
}
