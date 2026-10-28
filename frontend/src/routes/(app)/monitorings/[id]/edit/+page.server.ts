import { error } from "@sveltejs/kit";
import type { MonitoringFormOptions } from "#lib/api/monitoring.ts";

export async function load({ fetch, params }) {
    const monitoringId = encodeURIComponent(params.id);
    const headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
    const [response, preferencesResponse] = await Promise.all([
        fetch(`/api/monitorings/${monitoringId}/form-options`, { headers }),
        fetch(`/api/monitorings/${monitoringId}/notification-preferences`, { headers }),
    ]);

    if (!response.ok || !preferencesResponse.ok) {
        error(response.status, "Monitoring options could not be loaded.");
    }

    return {
        form: (await response.json() as { data: MonitoringFormOptions }).data,
        preferences: (await preferencesResponse.json() as { data: NotificationPreferences }).data,
    };
}

interface NotificationPreferences {
    effective: { notification_on_failure: boolean; notification_channels: string[]; ssl_expiry_warning_days: number; };
    permitted_channels: string[];
    can_update: boolean;
}
