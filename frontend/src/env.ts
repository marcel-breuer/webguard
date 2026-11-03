import { defineEnvVars } from "@sveltejs/kit/env";

export const variables = defineEnvVars({
    GTM_CONTAINER_ID: {
        schema: (value) => value?.trim() || undefined,
        description: "Optional Google Tag Manager container ID.",
    },
    GA4_MEASUREMENT_ID: {
        schema: (value) => value?.trim() || undefined,
        description: "Optional Google Analytics 4 measurement ID.",
    },
});
