<script lang="ts">
    import { cn, type WithElementRef } from "#lib/utils.ts";
    import type { HTMLInputAttributes } from "svelte/elements";

    let {
        ref = $bindable(null),
        checked = $bindable(false),
        class: className,
        ...attributes
    }: WithElementRef<Omit<HTMLInputAttributes, "type">, HTMLInputElement> = $props();
</script>

<input
    {...attributes}
    bind:this={ref}
    bind:checked
    type="checkbox"
    data-slot="checkbox"
    class={cn("size-5 shrink-0 rounded-full border-wg-border text-wg-accent accent-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:opacity-60", className)}
/>
