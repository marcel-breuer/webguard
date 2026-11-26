<script lang="ts">
    import { Field, FieldLabel } from "#lib/components/ui/field/index.ts";
    import { Button as LinkButton } from "#lib/components/ui/button/index.ts";
    import * as Alert from "#lib/components/ui/alert/index.ts";
    import * as Card from "#lib/components/ui/card/index.ts";
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

<main class="min-h-screen px-4 py-8 sm:px-6 sm:py-14">
    <Card.Root as="section" class="mx-auto w-full max-w-lg">
        <Card.Content class="p-6 sm:p-8">
            <LinkButton class="size-10 min-h-0 rounded-xl border-0 p-0 text-base font-black tracking-normal shadow-none" href="/" aria-label="WebGuard">W</LinkButton>
            <h1 class="mt-5 text-3xl font-bold tracking-tight">Unsubscribe from updates</h1>
            <p class="mt-3 text-sm leading-6 text-wg-text-muted">Enter the email address that receives these status updates to confirm the removal.</p>

            <form method="POST" class="mt-6 grid gap-4" novalidate>
                <Field data-invalid={form?.error ? true : undefined}>
                    <FieldLabel class="text-sm font-bold text-wg-text" for="unsubscribe-email">Email address</FieldLabel>
                    <Input id="unsubscribe-email" aria-invalid={form?.error ? true : undefined} name="email" type="email" autocomplete="email" required aria-describedby={form?.error ? "unsubscribe-email-error" : undefined} value={form?.email ?? ""} />
                    {#if form?.error}
                        <Alert.Root id="unsubscribe-email-error" variant="destructive"><Alert.Description>{form.error}</Alert.Description></Alert.Root>
                    {/if}
                </Field>
                <Button variant="danger" type="submit">Unsubscribe</Button>
            </form>
        </Card.Content>
    </Card.Root>
</main>
