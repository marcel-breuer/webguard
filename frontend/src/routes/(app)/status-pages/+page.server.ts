import { error } from "@sveltejs/kit";
import type { StatusPage, StatusPageMonitoring, StatusPageMonitoringGroup } from "#lib/api/status-pages.ts";

export async function load({ fetch }) {
    const headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
    const [statusPagesResponse, optionsResponse] = await Promise.all([
        fetch("/api/status-pages?per_page=100", { headers }),
        fetch("/api/status-pages/options", { headers }),
    ]);

    if (!statusPagesResponse.ok || !optionsResponse.ok) {
        error(statusPagesResponse.ok ? optionsResponse.status : statusPagesResponse.status, "Status pages could not be loaded.");
    }

    return {
        statusPages: await statusPagesResponse.json() as { data: StatusPage[] },
        options: await optionsResponse.json() as { data: { monitorings: StatusPageMonitoring[]; monitoring_groups: StatusPageMonitoringGroup[] } },
    };
}
