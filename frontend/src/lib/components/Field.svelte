<script lang="ts">
    import { Label } from "#lib/components/ui/label/index.ts";
    import type { Snippet } from "svelte";

    interface Props {
        label: string;
        hint?: string;
        error?: string;
        required?: boolean;
        children?: Snippet;
    }

    let { label, hint, error, required = false, children }: Props = $props();
</script>

<Label>
    <span class="text-sm leading-5 font-bold">
        {label}
        {#if required}<span aria-hidden="true">*</span>{/if}
    </span>
    {@render children?.()}
    {#if error}
        <span class="text-[0.8125rem] leading-5 text-wg-danger" role="alert">{error}</span>
    {:else if hint}
        <span class="text-[0.8125rem] leading-5 text-wg-text-muted">{hint}</span>
    {/if}
</Label>
