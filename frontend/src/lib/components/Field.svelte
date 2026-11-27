<script lang="ts">
    import { Field, FieldDescription, FieldError } from "#lib/components/ui/field/index.ts";
    import { Label } from "#lib/components/ui/label/index.ts";
    import type { Snippet } from "svelte";

    interface Props {
        label: string;
        hint?: string;
        error?: string;
        required?: boolean;
        children?: Snippet;
    }

    const uid = $props.id();
    const messageId = `${uid}-message`;
    let { label, hint, error, required = false, children }: Props = $props();
</script>

<Field class="w-auto gap-2" aria-describedby={error || hint ? messageId : undefined}>
    <Label>
        <span class="text-sm leading-5 font-medium">
            {label}
            {#if required}<span aria-hidden="true">*</span>{/if}
        </span>
        {@render children?.()}
    </Label>
    {#if error}
        <FieldError id={messageId} class="text-sm leading-5 text-wg-danger">{error}</FieldError>
    {:else if hint}
        <FieldDescription id={messageId} class="text-sm leading-5 text-wg-text-muted">{hint}</FieldDescription>
    {/if}
</Field>
