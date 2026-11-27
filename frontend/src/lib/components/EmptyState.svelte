<script lang="ts">
    import InboxIcon from "@lucide/svelte/icons/inbox";
    import * as Empty from "#lib/components/ui/empty/index.ts";
    import type { Snippet } from "svelte";
    interface Props { title: string; description: string; action?: Snippet; }
    let { title, description, action }: Props = $props();
</script>
<Empty.Root class="wg-glass-inset gap-4 rounded-2xl border-wg-border px-6 py-10 text-center" aria-live="polite">
    <Empty.Header class="max-w-128 gap-2">
        <Empty.Media variant="icon" class="size-10 rounded-lg bg-wg-surface-muted text-wg-text-muted" aria-hidden="true"><InboxIcon class="size-[18px]" /></Empty.Media>
        <Empty.Title role="heading" aria-level={2} class="text-lg font-semibold">{title}</Empty.Title>
        <Empty.Description class="text-sm leading-6 text-wg-text-muted">{description}</Empty.Description>
    </Empty.Header>
    {#if action}<Empty.Content>{@render action()}</Empty.Content>{/if}
</Empty.Root>
