<script lang="ts">
    import { formatLocale } from "$lib/i18n/format";
    import type { HTMLInputAttributes } from "svelte/elements";

    interface Props extends Omit<HTMLInputAttributes, "value"> {
        value?: string | number | readonly string[];
    }

    let { value = $bindable(), class: className = "", type, lang, ...attributes }: Props = $props();
    const localizedInputTypes = new Set(["date", "datetime-local", "month", "number", "time", "week"]);
    const inputLocale = $derived(lang ?? (localizedInputTypes.has(type ?? "") ? formatLocale() : undefined));
</script>

<input
    {...attributes}
    {type}
    lang={inputLocale}
    bind:value
    class={`min-h-11 w-full rounded-md border border-wg-border bg-wg-surface px-3 py-2 text-sm text-wg-text shadow-xs transition-colors placeholder:text-wg-text-muted focus:border-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:bg-wg-surface-muted disabled:text-wg-text-muted read-only:bg-wg-surface-muted ${className}`}
/>
