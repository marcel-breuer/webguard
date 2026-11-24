<script lang="ts">
    import * as Card from "#lib/components/ui/card/index.ts";
</script>

<svelte:head>
    <title>Confirming subscription | WebGuard Status</title>
</svelte:head>

<main class="min-h-screen px-4 py-8 sm:px-6 sm:py-14">
    <Card.Root as="section" class="mx-auto w-full max-w-lg">
        <Card.Content class="p-6 sm:p-8">
            <a class="inline-flex size-10 items-center justify-center rounded-xl bg-wg-accent font-black text-wg-accent-contrast no-underline" href="/" aria-label="WebGuard">W</a>
            <h1 class="mt-5 text-3xl font-bold tracking-tight">Confirming your subscription</h1>
            <p class="mt-3 text-sm leading-6 text-wg-text-muted">Your confirmation is being processed.</p>
        </Card.Content>
    </Card.Root>
</main>
