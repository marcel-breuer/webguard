import { loadFirstPartySession } from "#lib/server/session.ts";
import { setAuthPageHeaders } from "#lib/server/auth.ts";

export async function load({ fetch, setHeaders }) {
    setAuthPageHeaders(setHeaders);
    return { session: await loadFirstPartySession(fetch) };
}
