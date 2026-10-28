import type { PaginationMeta } from "#lib/api/monitoring.ts";

export interface IncidentAnalyticsResponse {
    data: {
        overview: {
            overall_state: "healthy" | "degraded" | "attention" | "new";
            summary: Record<"total" | "healthy" | "down" | "unknown" | "paused" | "maintenance", number>;
        };
        groups: Array<OperationalSummary & { id: string; name: string; monitoring_count: number }>;
        status_pages: Array<OperationalSummary & { id: string; name: string; is_public: boolean; component_count: number }>;
        filters: IncidentAnalyticsFilters;
        filter_options: {
            incident_types: AnalyticsOption[];
            severities: AnalyticsOption[];
            customer_impacts: AnalyticsOption[];
        };
        metrics: { total: number; resolved: number; open: number; mttr_minutes: number | null };
        trend: { points: IncidentTrendPoint[]; max: number };
        repeat_services: Array<{ service: string; count: number }>;
        distributions: {
            by_type: AnalyticsDistribution[];
            by_severity: AnalyticsDistribution[];
            by_impact: AnalyticsDistribution[];
        };
        incidents: IncidentAnalyticsItem[];
    };
    meta: { as_of: string; incident_pagination: PaginationMeta };
}

export interface IncidentAnalyticsFilters {
    days: 30 | 90 | 365;
    incident_type: string | null;
    severity: string | null;
    customer_impact: string | null;
    affected_service: string | null;
    sort: "status" | "affected_service" | "down_at" | "up_at";
    direction: "asc" | "desc";
}

export interface AnalyticsOption { value: string; label: string; }
export interface AnalyticsDistribution { key: string; label: string; count: number; }
export interface IncidentTrendPoint { label: string; count: number; x: number; y: number; }
export interface OperationalSummary { total: number; healthy: number; down: number; attention: number; state: "healthy" | "degraded" | "attention" | "new"; }

export interface IncidentAnalyticsItem {
    id: string;
    monitoring_id: string;
    monitoring_name: string;
    affected_service: string;
    status: "open" | "resolved";
    incident_type: string;
    severity: string;
    customer_impact: string;
    problem_description: string | null;
    down_at: string;
    up_at: string | null;
    duration_minutes: number | null;
}
