import type { LayoutServerLoad } from "./$types";
import type { TranslationMessages } from "#lib/i18n/localize.ts";
import { GA4_MEASUREMENT_ID, GTM_CONTAINER_ID } from "$app/env/private";

interface TranslationResponse {
    data?: {
        messages?: TranslationMessages;
    };
}

export const load: LayoutServerLoad = async ({ cookies, fetch, request }) => {
    const gtmContainerId = GTM_CONTAINER_ID?.trim();
    const ga4MeasurementId = GA4_MEASUREMENT_ID?.trim();
    const cookieLocale = cookies.get("webguard_locale");
    const preferredLocale = request.headers.get("accept-language")?.toLowerCase().startsWith("de") ? "de" : "en";
    const locale = cookieLocale === "de" || cookieLocale === "en" ? cookieLocale : preferredLocale;
    let messages: TranslationMessages = {};

    try {
        const response = await fetch(`/api/translations?locale=${locale}`, {
            headers: { Accept: "application/json" },
        });

        if (response.ok) {
            const payload = await response.json() as TranslationResponse;
            messages = payload.data?.messages ?? {};
        }
    } catch {
        // Keep the English source text as the safe fallback if Laravel is unavailable.
    }

    return {
        locale,
        messages,
        formatLocale: null,
        analytics: {
            enabled: Boolean(gtmContainerId || ga4MeasurementId),
            gtmContainerId: gtmContainerId && /^GTM-[A-Z0-9]+$/.test(gtmContainerId) ? gtmContainerId : null,
            ga4MeasurementId: ga4MeasurementId && /^G-[A-Z0-9]+$/.test(ga4MeasurementId) ? ga4MeasurementId : null,
        },
    };
};
