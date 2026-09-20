export interface PublicIncidentUpdate {
    status: string;
    message: string;
    published_at: string | null;
}

export interface PublicIncident {
    monitoring_name: string;
    down_at: string | null;
    up_at: string | null;
    updates: PublicIncidentUpdate[];
}

export interface PublicStatusPayload {
    kind: "status_page" | "monitoring";
    identifier: string;
    name: string;
    description: string | null;
    status: "up" | "down" | "unknown";
    announcement?: { title: string; message: string; published_at: string | null } | null;
    components?: Array<{
        id: string;
        name: string;
        description: string | null;
        status: "up" | "down" | "unknown";
        has_maintenance: boolean;
        monitorings: Array<{
            id: string;
            name: string;
            type: string;
            status: "up" | "down" | "unknown";
            is_under_maintenance: boolean;
            last_checked_at: string | null;
        }>;
    }>;
    monitoring?: {
        type: string;
        target: string | null;
        is_under_maintenance: boolean;
        status_since: string | null;
        last_checked_at: string | null;
        http_status_code: number | null;
        maintenance_window: { active: boolean; starts_at: string | null; ends_at: string | null } | null;
        uptime: Record<string, { has_data: boolean; uptime: { percentage: number | null }; downtime: { incidents_count?: number } }>;
    };
    incidents: PublicIncident[];
    uptime_calendar?: Record<string, { days: Array<{ date: string; uptime_percentage: number | null }>; monthly_average_uptime: number | null }>;
}
