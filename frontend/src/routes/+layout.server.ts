import type { LayoutServerLoad } from "./$types";
import type { TranslationMessages } from "$lib/i18n/localize";

interface TranslationResponse {
    data?: {
        messages?: TranslationMessages;
    };
}

export const load: LayoutServerLoad = async ({ cookies, fetch, request }) => {
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

    return { locale, messages, formatLocale: null };
};
