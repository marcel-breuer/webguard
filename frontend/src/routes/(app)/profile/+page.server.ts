import { error } from "@sveltejs/kit";
import type { ApiEnvelope } from "#lib/api/models.ts";

interface ApiKeySummary {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    revoked_at: string | null;
}

export async function load({ fetch }) {
    const response = await fetch("/api/profile/api-keys", {
        headers: { Accept: "application/json" },
    });

    if (!response.ok) {
        error(response.status, "API keys could not be loaded.");
    }

    return {
        apiKeys: ((await response.json()) as ApiEnvelope<ApiKeySummary[]>).data,
    };
}
