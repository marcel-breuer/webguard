<script lang="ts">
    import { page } from "$app/state";
    import { appRoutes } from "#lib/routes.ts";
</script>

<svelte:head>
    <title>Unable to load | WebGuard</title>
</svelte:head>

<main class="mx-auto grid min-h-screen max-w-2xl content-center gap-4 p-8">
    <p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.12em] text-wg-accent uppercase">{page.status}</p>
    <h1 class="m-0 text-[clamp(2rem,8vw,3.5rem)] leading-[1.05]">Unable to load this page</h1>
    <p class="m-0 leading-[1.6] text-wg-text-muted">{page.error?.message ?? "Please try again in a moment."}</p>
    <a class="w-fit rounded-xl bg-wg-accent px-4 py-3 font-bold text-wg-accent-contrast no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus" href={appRoutes.dashboard}>Return to dashboard</a>
</main>
