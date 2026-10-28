import { error } from "@sveltejs/kit";
import type { ApiEnvelope } from "#lib/api/models.ts";

export interface NotificationEntry {
    id: string;
    event_type: string;
    severity: "critical" | "warning" | "info";
    message: string;
    occurred_at: string | null;
    read: boolean;
    delivery_status: "failed" | "unknown";
    monitoring: { id: string; name: string; target: string };
    cursor: string;
}

export interface NotificationInboxMeta {
    next_cursor: string | null;
    has_more: boolean;
    unread_count: number;
}

export async function load({ fetch }) {
    const response = await fetch("/api/notifications?limit=5&show_read=0", {
        headers: { Accept: "application/json" },
    });

    if (!response.ok) {
        error(response.status, "Notifications could not be loaded.");
    }

    const payload = (await response.json()) as ApiEnvelope<
        NotificationEntry[]
    > & { meta: NotificationInboxMeta };

    return {
        entries: payload.data,
        meta: payload.meta,
    };
}
