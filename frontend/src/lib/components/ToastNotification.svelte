<script lang="ts">
    import XIcon from "@lucide/svelte/icons/x";
    import { Button } from "#lib/components/ui/button/index.ts";
    import { Card } from "#lib/components/ui/card/index.ts";
    import type { ToastMessage } from "#lib/components/ToastRegion.svelte";

    let { item, dismiss }: { item: ToastMessage; dismiss: () => void } = $props();
</script>

<Card class={`flex w-full items-center justify-between gap-4 px-4 py-3 ${item.tone === "error" ? "bg-wg-status-down text-wg-status-down-foreground" : item.tone === "success" ? "bg-wg-status-up text-wg-status-up-foreground" : ""}`}>
    <p class="m-0 text-sm font-medium" role={item.tone === "error" ? "alert" : "status"}>{item.message}</p>
    <Button variant="dismiss" size="dismiss" type="button" aria-label="Dismiss notification" onclick={dismiss}><XIcon class="size-4" /></Button>
</Card>
