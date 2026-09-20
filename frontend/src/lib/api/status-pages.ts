export interface StatusPageMonitoring {
    id: string;
    name: string;
    target: string;
}

export interface StatusPageMonitoringGroup {
    id: string;
    name: string;
    monitorings_count: number;
}

export interface StatusPageComponent {
    id?: string;
    name: string;
    description: string | null;
    position?: number;
    source_type: "manual" | "monitoring_group";
    monitoring_group: { id: string; name: string; monitoring_count: number } | null;
    monitorings: StatusPageMonitoring[];
}

export interface StatusPage {
    id: string;
    name: string;
    description: string | null;
    publication: { is_public: boolean; can_change: boolean };
    component_count: number;
    verified_subscriber_count: number;
    open_incident_count: number;
    announcement: {
        id: string;
        title: string;
        message: string;
        notify_subscribers: boolean;
        notified_at: string | null;
        published_at: string | null;
    } | null;
    components: StatusPageComponent[];
    created_at: string | null;
    updated_at: string | null;
}

export interface StatusPageIncident {
    id: string;
    monitoring: StatusPageMonitoring;
    lifecycle: { state: "open" | "resolved"; opened_at: string | null; resolved_at: string | null };
    readiness: { can_publish_update: boolean; requires_public_update: boolean; update_count: number };
    updates: Array<{ id: string; status: string; message: string; published_at: string | null }>;
}
