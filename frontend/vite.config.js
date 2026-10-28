import adapter from "@sveltejs/adapter-node";
import { sveltekit } from "@sveltejs/kit/vite";
import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";

const hmrHost = process.env.VITE_HMR_HOST;

export default defineConfig({
    plugins: [
        tailwindcss(),
        sveltekit({
            adapter: adapter({
                out: "build",
                precompress: true,
            }),
        }),
    ],
    server: {
        host: "0.0.0.0",
        port: 3000,
        strictPort: true,
        allowedHosts: hmrHost ? [hmrHost] : ["webguard.test"],
        hmr: hmrHost
            ? {
                  host: hmrHost,
                  protocol: process.env.VITE_HMR_PROTOCOL ?? "ws",
                  clientPort: Number(process.env.VITE_HMR_CLIENT_PORT ?? 80),
              }
            : undefined,
        watch: {
            usePolling: process.env.VITE_USE_POLLING === "true",
            interval: Number(process.env.VITE_POLLING_INTERVAL ?? 300),
        },
    },
});
