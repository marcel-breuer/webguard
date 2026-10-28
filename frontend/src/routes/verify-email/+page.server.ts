import { redirect } from "@sveltejs/kit";
import { loadFirstPartySession } from "#lib/server/session.ts";
import { setAuthPageHeaders } from "#lib/server/auth.ts";

export async function load({ fetch, setHeaders }) {
    setAuthPageHeaders(setHeaders);
    const session = await loadFirstPartySession(fetch);

    if (session.user.is_verified) {
        redirect(303, "/dashboard");
    }

    return { session };
}
