import { loadGuestAuthContext, setAuthPageHeaders } from "#lib/server/auth.ts";

export async function load({ fetch, params, setHeaders, url }) {
    setAuthPageHeaders(setHeaders);
    return {
        ...(await loadGuestAuthContext(fetch)),
        email: url.searchParams.get("email") ?? "",
        token: params.token,
    };
}
