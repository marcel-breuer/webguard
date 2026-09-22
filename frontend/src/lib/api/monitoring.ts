export interface PaginationMeta {
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface DashboardService {
    id: string;
    name: string;
    target: string;
    type: string | null;
    group: string;
    status: string;
    open_incident: boolean;
    last_checked_at: string | null;
    response_time_ms: number | null;
}

export interface DashboardResponse {
    data: {
        overall_state: string;
        summary: Record<"total" | "healthy" | "down" | "unknown" | "paused" | "maintenance", number>;
        services: DashboardService[];
        attention: Array<{
            type: string;
            count: number | null;
            monitoring_id: string | null;
            monitoring_name: string | null;
            monitoring_target: string | null;
        }>;
        maintenance: Array<{
            monitoring_id: string;
            monitoring_name: string;
            monitoring_target: string;
            status: "active" | "upcoming";
            starts_at: string | null;
            ends_at: string | null;
        }>;
        recent_incidents: Array<{
            id: string;
            monitoring_id: string | null;
            monitoring_name: string | null;
            monitoring_target: string | null;
            down_at: string | null;
            up_at: string | null;
            resolved: boolean;
        }>;
        trend: Array<{
            date: string;
            label: string;
            uptime_percentage: number | null;
            has_data: boolean;
        }>;
        failed_delivery_count: number;
        recommended_action: string;
        capabilities: {
            can_create_monitoring: boolean;
            can_manage_maintenance: boolean;
        };
    };
    meta: {
        as_of: string;
        service_pagination: PaginationMeta;
    };
}

export interface MonitoringSummary {
    id: string;
    name: string;
    target: string;
    type: string | null;
    lifecycle_status: "active" | "paused";
    groups: Array<{ id: string; name: string }>;
    latest_check: {
        status: "up" | "down" | "unknown";
        checked_at: string | null;
        response_time_ms: number | null;
    } | null;
    open_incident: boolean;
    can_manage?: boolean;
    initial_results_wait_minutes?: number | null;
    maintenance: {
        starts_at: string | null;
        ends_at: string | null;
        has_recurring_window: boolean;
    };
}

export interface MonitoringListResponse {
    data: MonitoringSummary[];
    links: Record<string, string | null>;
    meta: PaginationMeta & { as_of: string };
}

export interface MonitoringHeatmapPoint {
    uptime: number;
    downtime: number;
}

export interface MonitoringCardPayload {
    status: "up" | "down" | "unknown" | null;
    since: string | null;
    heatmap: MonitoringHeatmapPoint[];
}

export interface MonitoringCardsResponse {
    data: Record<string, MonitoringCardPayload>;
    summary: {
        attention: number;
        healthy: number;
        paused: number;
        maintenance: number;
    };
}

export interface MonitoringFormConfiguration {
    id: string;
    name: string;
    type: MonitoringType;
    target: string;
    status: "active" | "paused";
    port: number | null;
    keyword: string | null;
    dns_record_type: string | null;
    dns_expected_values: string[] | null;
    timeout: number | null;
    http_method: "get" | "post" | "put" | "patch" | "delete" | null;
    expected_http_statuses: string | null;
    preferred_locations: string[];
    group_ids: string[];
    can_assign_groups: boolean;
    ownership: {
        type: "private" | "team";
        team_id: string | null;
        team_name: string | null;
    };
    notification_on_failure: boolean;
    notification_channels: string[] | null;
    failure_confirmation_threshold: number | null;
    ssl_expiry_warning_days: number | null;
    heartbeat_interval_minutes: number | null;
    heartbeat_grace_minutes: number | null;
    server_health_cpu_threshold_percent: number | null;
    server_health_ram_threshold_percent: number | null;
    server_health_storage_threshold_percent: number | null;
    server_health_report_interval_minutes: number | null;
    server_health_grace_minutes: number | null;
}

export type MonitoringType = "http" | "ping" | "keyword" | "port" | "heartbeat" | "server_health" | "domain_expiration" | "dns_record";

export interface MonitoringFormOptions {
    types: MonitoringType[];
    locations: string[];
    groups: Array<{ id: string; name: string }>;
    teams: Array<{ id: string; name: string }>;
    notification_channels: string[];
    monitoring: MonitoringFormConfiguration | null;
}

export interface MonitoringMutationResult {
    id: string;
    name: string;
    type: MonitoringType;
    lifecycle_status: "active" | "paused";
}

export interface MonitoringDetailData {
    summary: {
        id: string;
        name: string;
        target: string;
        type: string;
        lifecycle_status: "active" | "paused";
        public_status_enabled: boolean;
        ownership: { type: "private" | "team"; can_manage: boolean; name: string | null };
        groups: Array<{ id: string; name: string }>;
        check_regions: string[];
        notification_channels: string[];
        status_pages: Array<{ id: string; name: string }>;
        open_incident: boolean;
    };
    current_check: {
        status: "up" | "down" | "unknown";
        checked_at: string | null;
        interval: number | null;
        status_code: number | null;
    };
    heatmap: MonitoringHeatmapPoint[];
    availability: {
        has_data: boolean;
        uptime: { percentage: number | null };
        downtime: { percentage: number | null; incidents_count: number };
        unknown: { percentage: number | null };
    };
    availability_periods: Record<string, {
        has_data: boolean;
        uptime: { percentage: number | null };
        downtime: { percentage: number | null; incidents_count: number };
        unknown: { percentage: number | null };
    }>;
    response_times: {
        data: Array<{ date: string; avg: number | null; min: number | null; max: number | null }>;
        aggregated: { avg: number | null; min: number | null; max: number | null };
    };
    incidents: Array<{ down_at: string; up_at: string | null }>;
    maintenance: {
        active: boolean;
        starts_at: string | null;
        ends_at: string | null;
        has_recurring_window: boolean;
    };
    uptime_calendar: Record<string, {
        days: Array<{ date: string; uptime_percentage: number | null }>;
        monthly_average_uptime: number | null;
    }>;
    recent_checks: Array<{
        id: string;
        checked_at: string;
        status: "up" | "down" | "unknown";
        http_status_code: number | null;
        response_time: number | null;
        source: "live" | "archived";
    }>;
    ssl: {
        valid: boolean;
        expiration: string | null;
        issuer: string | null;
        issue_date: string | null;
    } | null;
    domain: {
        valid: boolean;
        expires_at: string | null;
        registrar: string | null;
        checked_at: string | null;
    } | null;
    server_health_telemetry: {
        data: Array<{
            checked_at: string;
            cpu_usage_percent: number | null;
            ram_usage_percent: number | null;
            storage_usage_percent: number | null;
            normalized_load: number | null;
        }>;
        thresholds: {
            cpu_usage_percent: number;
            ram_usage_percent: number;
            storage_usage_percent: number;
            load_per_cpu: number | null;
        };
    } | null;
}

export interface MonitoringDetailMeta {
    incidents: {
        limit: number;
        offset: number;
        has_more: boolean;
        next_offset: number | null;
    };
    recent_checks: {
        limit: number;
        has_more: boolean;
        next_offset: number | null;
    };
    response_times: {
        days: 1 | 7 | 30;
    };
    uptime_calendar: {
        oldest_available_month: string | null;
    };
}
