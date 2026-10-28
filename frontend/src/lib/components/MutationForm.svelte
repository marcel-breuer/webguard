<script lang="ts" generics="T">
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import Button from "#lib/components/Button.svelte";
    import type { Snippet } from "svelte";

    interface Props {
        action: string;
        method?: "POST" | "PATCH" | "PUT" | "DELETE";
        submitLabel: string;
        successMessage?: string;
        submitAsJson?: boolean;
        submitDisabled?: boolean;
        children?: Snippet;
        onSuccess?: (data: T) => void | Promise<void>;
    }

    let {
        action,
        method = "POST",
        submitLabel,
        successMessage = "Saved successfully.",
        submitAsJson = false,
        submitDisabled = false,
        children,
        onSuccess,
    }: Props = $props();
    let submitting = $state(false);
    let message = $state("");
    let errors = $state<Record<string, string[]>>({});
    let sessionRecovery = $state(false);

    async function submit(event: SubmitEvent): Promise<void> {
        event.preventDefault();

        if (submitting) {
            return;
        }

        submitting = true;
        message = "";
        errors = {};
        sessionRecovery = false;

        try {
            const form = event.currentTarget as HTMLFormElement;
            const body = new FormData(form);
            const response = await requestFirstPartyApi<T>(action, {
                body: submitAsJson ? JSON.stringify(Object.fromEntries(body)) : body,
                method,
            });

            message = successMessage;
            await onSuccess?.(response.data);
        } catch (error) {
            if (error instanceof FirstPartyApiError) {
                errors = error.errors;
                sessionRecovery = error.status === 401 || error.status === 419;
                message = sessionRecovery
                    ? "Your session has expired. Sign in again, then retry without losing the values entered here."
                    : error.message;
            } else {
                message = "The request could not be completed. Please try again.";
            }
        } finally {
            submitting = false;
        }
    }
</script>

<form class="grid gap-4" onsubmit={submit} novalidate>
    {@render children?.()}

    {#if message}
        <p
            class={`m-0 text-sm font-semibold ${Object.keys(errors).length > 0 ? "text-wg-danger" : "text-green-700 dark:text-green-300"}`}
            role={Object.keys(errors).length > 0 ? "alert" : "status"}
        >
            {message}
        </p>
        {#if sessionRecovery}<a class="text-sm font-bold text-wg-accent no-underline hover:underline" href="/login?expired=1">Sign in again</a>{/if}
    {/if}

    <Button type="submit" disabled={submitDisabled} loading={submitting}>{submitLabel}</Button>
</form>
