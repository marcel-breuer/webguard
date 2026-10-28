import type { HandleFetch } from "@sveltejs/kit/hooks";

const correlationIdPattern = /^(?:[\da-f]{32}|[\da-f]{8}-(?:[\da-f]{4}-){3}[\da-f]{12})$/i;

export const handleFetch: HandleFetch = async ({ event, fetch, request }) => {
    const requestId = event.request.headers.get("x-request-id");
    const forwardedFor = event.request.headers.get("x-forwarded-for");
    const cookie = event.request.headers.get("cookie");

    if (new URL(request.url).origin === event.url.origin) {
        if (cookie) {
            request.headers.set("cookie", cookie);
        }

        if (requestId && correlationIdPattern.test(requestId)) {
            request.headers.set("X-Request-Id", requestId);
        }

        if (forwardedFor) {
            request.headers.set("X-Forwarded-For", forwardedFor);
        }
    }

    return fetch(request);
};
