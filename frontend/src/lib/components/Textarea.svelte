<script lang="ts">
    import { Textarea as TextareaPrimitive } from "#lib/components/ui/textarea/index.ts";
    import type { HTMLTextareaAttributes } from "svelte/elements";

    interface Props extends Omit<HTMLTextareaAttributes, "value"> {
        value?: string;
    }

    let { value = $bindable(), class: className = "", ...attributes }: Props = $props();
</script>

<TextareaPrimitive {...attributes} bind:value class={className} />
