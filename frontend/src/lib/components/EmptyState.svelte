<script lang="ts">
    import * as Empty from "#lib/components/ui/empty/index.ts";
    import type { Snippet } from "svelte";
    interface Props { title: string; description: string; action?: Snippet; }
    let { title, description, action }: Props = $props();
</script>
<Empty.Root class="gap-3 rounded-2xl border-dashed border-wg-border px-6 py-12 text-center" aria-live="polite">
    <Empty.Header class="max-w-128 gap-3">
        <Empty.Media variant="icon" class="size-11 rounded-full text-2xl text-wg-accent" aria-hidden="true">○</Empty.Media>
        <Empty.Title role="heading" aria-level={2} class="text-lg font-bold">{title}</Empty.Title>
        <Empty.Description class="leading-6 text-wg-text-muted">{description}</Empty.Description>
    </Empty.Header>
    {#if action}<Empty.Content>{@render action()}</Empty.Content>{/if}
</Empty.Root>
