import type { ApiEnvelope, ApiValidationErrors } from "#lib/api/models.ts";

export class FirstPartyApiError extends Error {
    public readonly status: number;
    public readonly errors: Record<string, string[]>;

    public constructor(status: number, payload: Partial<ApiValidationErrors>) {
        super(payload.message ?? "The request could not be completed.");
        this.name = "FirstPartyApiError";
        this.status = status;
        this.errors = payload.errors ?? {};
    }
}

let csrfEndpoint = "/sanctum/csrf-cookie";
let csrfBootstrapped = false;

function xsrfToken(): string | undefined {
    const token = document.cookie
        .split("; ")
        .find((cookie) => cookie.startsWith("XSRF-TOKEN="))
        ?.split("=")
        .slice(1)
        .join("=");

    return token ? decodeURIComponent(token) : undefined;
}

export function setCsrfEndpoint(endpoint: string): void {
    csrfEndpoint = endpoint;
}

export async function requestFirstPartyApi<T, TMeta = Record<string, unknown>>(
    path: string,
    init: RequestInit = {},
): Promise<ApiEnvelope<T, TMeta>> {
    const method = (init.method ?? "GET").toUpperCase();

    if (!["GET", "HEAD", "OPTIONS"].includes(method) && !csrfBootstrapped) {
        const response = await fetch(csrfEndpoint, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });

        if (!response.ok) {
            throw new FirstPartyApiError(response.status, {});
        }

        csrfBootstrapped = true;
    }

    const csrfToken = !["GET", "HEAD", "OPTIONS"].includes(method)
        ? xsrfToken()
        : undefined;

    const response = await fetch(path, {
        ...init,
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            ...(init.body instanceof FormData
                ? {}
                : { "Content-Type": "application/json" }),
            ...(csrfToken ? { "X-XSRF-TOKEN": csrfToken } : {}),
            ...init.headers,
        },
    });

    if (!response.ok) {
        const payload = (await response
            .json()
            .catch(() => ({}))) as Partial<ApiValidationErrors>;
        throw new FirstPartyApiError(response.status, payload);
    }

    if (response.status === 204) {
        return { data: undefined as T };
    }

    return (await response.json()) as ApiEnvelope<T, TMeta>;
}
