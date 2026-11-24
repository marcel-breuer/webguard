<script lang="ts">
    import { Checkbox as CheckboxPrimitive } from "#lib/components/ui/checkbox/index.ts";
    import type { HTMLInputAttributes } from "svelte/elements";

    interface Props extends Omit<HTMLInputAttributes, "checked" | "type"> {
        checked?: boolean;
        group?: string[];
    }

    let { checked = $bindable(false), group = $bindable<string[] | undefined>(), class: className = "", ...attributes }: Props = $props();

    function groupValue(): string {
        return String(attributes.value ?? "");
    }

    function toggleGroup(event: Event): void {
        const input = event.currentTarget as HTMLInputElement;
        const value = input.value;
        const current = group ?? [];

        group = input.checked
            ? current.includes(value) ? current : [...current, value]
            : current.filter((item) => item !== value);
    }
</script>

{#if group}
    <CheckboxPrimitive
        {...attributes}
        checked={group.includes(groupValue())}
        onchange={toggleGroup}
        class={className}
    />
{:else}
    <CheckboxPrimitive
        {...attributes}
        bind:checked
        class={className}
    />
{/if}
