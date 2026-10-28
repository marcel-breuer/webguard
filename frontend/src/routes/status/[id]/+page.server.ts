import { error, fail, redirect } from "@sveltejs/kit";
import type { PublicStatusPayload } from "#lib/api/public-status.ts";
import type { TranslationMessages } from "#lib/i18n/localize.ts";
import type { Actions } from "./$types";

interface SubscriptionResponse {
    data: {
        message: string;
    };
}

interface ApiErrorResponse {
    errors?: Record<string, string[]>;
    message?: string;
}

export async function load({ fetch, params, parent, url }) {
    const response = await fetch(`/api/public/status/${encodeURIComponent(params.id)}`, {
        headers: { Accept: "application/json" },
    });

    if (!response.ok) {
        error(response.status, "This public status page is unavailable.");
    }

    const payload = await response.json() as { data: PublicStatusPayload };

    if (payload.data.identifier !== params.id) {
        redirect(301, `/status/${encodeURIComponent(payload.data.identifier)}${url.search}`);
    }

    const subscription = url.searchParams.get("subscription");
    const { locale, messages } = await parent();

    return {
        ...payload.data,
        locale,
        messages: messages as TranslationMessages,
        subscriptionNotice: subscription === "confirmation-sent"
            ? "Check your inbox to confirm your subscription."
            : subscription === "confirmed"
                ? "Your subscription has been confirmed."
                : subscription === "unsubscribed"
                    ? "You have been unsubscribed."
                    : null,
    };
}

export const actions = {
    default: async ({ fetch, params, request }) => {
        const formData = await request.formData();
        const email = String(formData.get("email") ?? "");
        const statusResponse = await fetch(`/api/public/status/${encodeURIComponent(params.id)}`, {
            headers: { Accept: "application/json" },
        });

        if (statusResponse.ok) {
            const payload = await statusResponse.json() as { data: PublicStatusPayload };

            if (payload.data.identifier !== params.id) {
                redirect(307, `/status/${encodeURIComponent(payload.data.identifier)}`);
            }
        }

        const response = await fetch(`/api/public/status/${encodeURIComponent(params.id)}/subscribers`, {
            body: formData,
            headers: { Accept: "application/json" },
            method: "POST",
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({})) as ApiErrorResponse;

            return fail(response.status === 422 ? 422 : 400, {
                email,
                error: payload.errors?.email?.[0] ?? payload.message ?? "The subscription could not be started.",
            });
        }

        const payload = await response.json() as SubscriptionResponse;

        return {
            email,
            message: payload.data.message,
        };
    },
} satisfies Actions;
