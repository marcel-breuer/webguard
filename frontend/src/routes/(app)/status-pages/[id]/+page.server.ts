import { error } from "@sveltejs/kit";
import type { StatusPage, StatusPageIncident } from "#lib/api/status-pages.ts";

export async function load({ fetch, params }) {
    const headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
    const [statusPageResponse, incidentsResponse] = await Promise.all([
        fetch(`/api/status-pages/${params.id}`, { headers }),
        fetch(`/api/status-pages/${params.id}/incidents?per_page=50`, { headers }),
    ]);

    if (!statusPageResponse.ok || !incidentsResponse.ok) {
        error(statusPageResponse.ok ? incidentsResponse.status : statusPageResponse.status, "Status page details could not be loaded.");
    }

    return {
        statusPage: await statusPageResponse.json() as { data: StatusPage },
        incidents: await incidentsResponse.json() as { data: StatusPageIncident[] },
    };
}
