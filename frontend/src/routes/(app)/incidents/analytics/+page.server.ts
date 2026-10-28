import { error } from "@sveltejs/kit";
import type { IncidentAnalyticsResponse } from "#lib/api/incidents.ts";

const permittedFilters = ["days", "incident_type", "severity", "customer_impact", "affected_service", "sort", "direction", "page"] as const;

export async function load({ fetch, url }) {
    const params = new URLSearchParams();

    for (const filter of permittedFilters) {
        const value = url.searchParams.get(filter);

        if (value) params.set(filter, value);
    }

    const query = params.size > 0 ? `?${params}` : "";
    const response = await fetch(`/api/incidents/analytics${query}`, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    });

    if (!response.ok) {
        error(response.status, "Incident analytics could not be loaded.");
    }

    return { analytics: await response.json() as IncidentAnalyticsResponse };
}
