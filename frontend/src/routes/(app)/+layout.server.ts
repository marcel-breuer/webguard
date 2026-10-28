import { loadFirstPartySession } from "#lib/server/session.ts";

export async function load({ fetch }) {
    const session = await loadFirstPartySession(fetch);

    return {
        session,
        formatLocale: session.user.format_locale,
    };
}
