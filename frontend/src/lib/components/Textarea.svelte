<script lang="ts">
    import type { HTMLTextareaAttributes } from "svelte/elements";

    interface Props extends Omit<HTMLTextareaAttributes, "value"> {
        value?: string;
    }

    let { value = $bindable(), class: className = "", ...attributes }: Props = $props();
</script>

<textarea
    {...attributes}
    bind:value
    class={`min-h-24 w-full wg-glass-control border border-wg-border px-3 py-2 text-sm text-wg-text transition-colors placeholder:text-wg-text-muted focus:border-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:bg-wg-surface-muted disabled:text-wg-text-muted read-only:bg-wg-surface-muted ${className}`}
></textarea>
