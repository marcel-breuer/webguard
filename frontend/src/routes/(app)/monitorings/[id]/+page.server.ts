import { error } from "@sveltejs/kit";
import type { MonitoringDetailData, MonitoringDetailMeta, MonitoringSummary } from "#lib/api/monitoring.ts";

export async function load({ fetch, params }) {
    const monitoringId = encodeURIComponent(params.id);
    const [response, detailResponse] = await Promise.all([
        fetch(`/api/monitorings/${monitoringId}`, {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        }),
        fetch(`/api/monitorings/${monitoringId}/detail-data`, {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        }),
    ]);

    if (!response.ok || !detailResponse.ok) {
        error(response.status, "Monitoring could not be loaded.");
    }

    const detailPayload = await detailResponse.json() as { data: MonitoringDetailData; meta: MonitoringDetailMeta };

    return {
        monitoring: (await response.json() as { data: MonitoringSummary }).data,
        detail: detailPayload.data,
        incidentsMeta: detailPayload.meta.incidents,
        recentChecksMeta: detailPayload.meta.recent_checks,
        uptimeCalendarMeta: detailPayload.meta.uptime_calendar,
    };
}
