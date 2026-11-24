<script lang="ts">
    import { Input as InputPrimitive } from "#lib/components/ui/input/index.ts";
    import { formatLocale } from "#lib/i18n/format.ts";
    import type { HTMLInputAttributes } from "svelte/elements";

    interface Props extends Omit<HTMLInputAttributes, "value"> {
        value?: string | number | readonly string[];
    }

    let { value = $bindable(), class: className = "", type, lang, ...attributes }: Props = $props();
    const localizedInputTypes = new Set(["date", "datetime-local", "month", "number", "time", "week"]);
    const inputLocale = $derived(lang ?? (localizedInputTypes.has(type ?? "") ? formatLocale() : undefined));
</script>

<InputPrimitive {...attributes} {type} lang={inputLocale} bind:value class={className} />
