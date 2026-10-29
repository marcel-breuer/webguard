export type AppearanceTheme = "light" | "dark" | "system";

export interface ApiEnvelope<T, TMeta = Record<string, unknown>> {
    data: T;
    meta?: TMeta;
}

export interface ApiValidationErrors {
    message: string;
    errors: Record<string, string[]>;
}

export interface FirstPartyTeam {
    id: string;
    name: string;
    role: "admin" | "member";
}

export interface FirstPartySession {
    user: {
        id: string;
        name: string;
        email: string;
        role: "admin" | "regular" | "demo";
        locale: string;
        format_locale: string | null;
        theme: AppearanceTheme;
        email_verified_at: string | null;
        is_verified: boolean;
        onboarding: {
            notification_tested_at: string | null;
            completed_at: string | null;
            dismissed_at: string | null;
        };
    };
    teams: FirstPartyTeam[];
    csrf_endpoint: string;
}
