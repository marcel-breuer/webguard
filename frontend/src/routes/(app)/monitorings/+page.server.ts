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
    const monitorings = fetch(`/api/monitorings${query}`, { headers })
        .then(async (response) => {
            if (!response.ok) return null;

            return await response.json() as MonitoringListResponse;
        })
        .catch(() => null);
    const dashboard = fetch("/api/dashboard", { headers })
        .then(async (dashboardResponse) => {
            if (!dashboardResponse.ok) return null;

            return await dashboardResponse.json() as DashboardResponse;
        })
        .catch(() => null);
    const cards = monitorings.then(async (monitoringList) => {
        if (monitoringList === null || monitoringList.data.length === 0) {
            return { data: {}, summary: { attention: 0, healthy: 0, paused: 0, maintenance: 0 } };
        }

        const cardParams = new URLSearchParams();
        for (const monitoring of monitoringList.data) {
            cardParams.append("ids[]", monitoring.id);
        }

        try {
            const cardsResponse = await fetch(`/api/monitorings/cards?${cardParams}`, { headers });

            return cardsResponse.ok
                ? await cardsResponse.json() as MonitoringCardsResponse
                : { data: {}, summary: { attention: 0, healthy: 0, paused: 0, maintenance: 0 } };
        } catch {
            return { data: {}, summary: { attention: 0, healthy: 0, paused: 0, maintenance: 0 } };
        }
    });

    return {
        filters: {
            lifecycleStatus: lifecycleStatus !== null && lifecycleStatuses.has(lifecycleStatus) ? lifecycleStatus : "",
            search,
        },
        streamed: { monitorings, dashboard, cards },
    };
}
