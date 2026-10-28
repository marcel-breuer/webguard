import { loadGuestAuthContext, setAuthPageHeaders } from "#lib/server/auth.ts";

export async function load({ fetch, setHeaders, url }) {
    setAuthPageHeaders(setHeaders);
    const context = await loadGuestAuthContext(fetch);
    const requestedMode = url.searchParams.get("mode");
    const initialMode = url.searchParams.get("guest") === "1" || url.searchParams.get("guest") === "true"
        ? "demo"
        : requestedMode === "register" || requestedMode === "demo" ? requestedMode : "login";

    return {
        ...context,
        initialEmail: url.searchParams.get("email") ?? "",
        initialMode,
        expired: url.searchParams.get("expired") === "1",
        notice: url.searchParams.get("reset") === "1" ? "Your password has been reset. You can now sign in." : "",
    };
}
