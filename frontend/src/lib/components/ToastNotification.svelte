<script lang="ts">
    import { Button } from "#lib/components/ui/button/index.ts";
    import { Card } from "#lib/components/ui/card/index.ts";
    import type { ToastMessage, ToastTone } from "#lib/components/ToastRegion.svelte";

    let { item, dismiss }: { item: ToastMessage; dismiss: () => void } = $props();

    const tones: Record<ToastTone, string> = {
        success: "border-l-green-600",
        error: "border-l-wg-danger",
        info: "border-l-wg-accent",
    };
</script>

<Card class={`flex w-full items-center justify-between gap-4 border-l-4 px-4 py-3.5 ${tones[item.tone]}`}>
    <p class="m-0 text-sm font-semibold" role={item.tone === "error" ? "alert" : "status"}>{item.message}</p>
    <Button variant="dismiss" size="dismiss" type="button" aria-label="Dismiss notification" onclick={dismiss}>×</Button>
</Card>
