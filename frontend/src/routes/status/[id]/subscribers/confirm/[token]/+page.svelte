<script lang="ts">
    import { Button as LinkButton } from "#lib/components/ui/button/index.ts";
    import * as Card from "#lib/components/ui/card/index.ts";
</script>

<svelte:head>
    <title>Confirming subscription | WebGuard Status</title>
</svelte:head>

<main class="min-h-screen px-4 py-8 sm:px-6 sm:py-14">
    <Card.Root as="section" class="mx-auto w-full max-w-lg">
        <Card.Content class="p-6 sm:p-8">
            <LinkButton class="size-10 min-h-0 rounded-xl border-0 p-0 text-base font-black tracking-normal shadow-none" href="/" aria-label="WebGuard">W</LinkButton>
            <h1 class="mt-5 text-3xl font-bold tracking-tight">Confirming your subscription</h1>
            <p class="mt-3 text-sm leading-6 text-wg-text-muted">Your confirmation is being processed.</p>
        </Card.Content>
    </Card.Root>
</main>
