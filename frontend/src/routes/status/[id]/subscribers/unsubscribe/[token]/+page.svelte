<script lang="ts">
    import Button from "#lib/components/Button.svelte";
    import Input from "#lib/components/Input.svelte";
    interface FormState {
        email?: string;
        error?: string;
    }

    interface Props {
        form?: FormState;
    }

    let { form }: Props = $props();
</script>

<svelte:head>
    <title>Unsubscribe from updates | WebGuard Status</title>
</svelte:head>

<main class="min-h-screen bg-wg-canvas px-4 py-8 sm:px-6 sm:py-14">
    <section class="mx-auto w-full max-w-lg rounded-2xl border border-wg-border bg-wg-surface p-6 shadow-sm sm:p-8">
        <a class="inline-flex size-10 items-center justify-center rounded-xl bg-wg-accent font-black text-wg-accent-contrast no-underline" href="/" aria-label="WebGuard">W</a>
        <h1 class="mt-5 text-3xl font-bold tracking-tight">Unsubscribe from updates</h1>
        <p class="mt-3 text-sm leading-6 text-wg-text-muted">Enter the email address that receives these status updates to confirm the removal.</p>

        <form method="POST" class="mt-6 grid gap-4" novalidate>
            <div class="grid gap-2">
                <label class="text-sm font-bold text-wg-text" for="unsubscribe-email">Email address</label>
                <Input id="unsubscribe-email" name="email" type="email" autocomplete="email" required aria-describedby={form?.error ? "unsubscribe-email-error" : undefined} value={form?.email ?? ""} />
                {#if form?.error}
                    <p id="unsubscribe-email-error" class="text-sm font-bold text-wg-danger" role="alert">{form.error}</p>
                {/if}
            </div>
            <Button variant="danger" type="submit">Unsubscribe</Button>
        </form>
    </section>
</main>
