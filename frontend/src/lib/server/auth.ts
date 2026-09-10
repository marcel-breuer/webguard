import { error, redirect } from "@sveltejs/kit";

export interface AuthOptions {
    captcha_url: string;
    imprint_url: string;
    terms_url: string;
    privacy_url: string;
}

export interface GuestAuthContext {
    options: AuthOptions;
}

export function setAuthPageHeaders(setHeaders: (headers: Record<string, string>) => void): void {
    setHeaders({
        "Cache-Control": "private, no-store, max-age=0",
        "X-Robots-Tag": "noindex, nofollow, noarchive, nosnippet, noimageindex",
    });
}

export async function loadGuestAuthContext(fetcher: typeof fetch): Promise<GuestAuthContext> {
    const session = await fetcher("/api/session", {
        headers: { Accept: "application/json" },
    });

    if (session.ok) {
        redirect(303, "/dashboard");
    }

    if (session.status !== 401) {
        error(session.status, "Your session could not be checked.");
    }

    const response = await fetcher("/api/auth/options", {
        headers: { Accept: "application/json" },
    });

    if (!response.ok) {
        error(response.status, "Authentication options could not be loaded.");
    }

    return { options: (await response.json() as { data: AuthOptions }).data };
}
