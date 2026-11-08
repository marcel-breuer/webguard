<script lang="ts">
    export type ToastTone = "success" | "error" | "info";
    export interface ToastMessage { id: string; tone: ToastTone; message: string; }
    interface Props { items?: ToastMessage[]; onDismiss?: (id: string) => void; }
    let { items = [], onDismiss }: Props = $props();

    const tones: Record<ToastTone, string> = {
        success: "border-l-green-600",
        error: "border-l-wg-danger",
        info: "border-l-wg-accent",
    };
</script>
<section class="fixed right-4 bottom-4 z-40 grid w-[min(24rem,calc(100vw_-_2rem))] gap-3" aria-label="Notifications" aria-live="polite">
    {#each items as item (item.id)}
        <div class={`flex items-center justify-between gap-4 rounded-xl border border-wg-border border-l-4 wg-glass-panel px-4 py-3.5 shadow-wg-surface ${tones[item.tone]}`} role={item.tone === "error" ? "alert" : "status"}>
            <p class="m-0 text-sm font-semibold">{item.message}</p><button class="border-0 bg-transparent text-xl text-wg-text-muted" type="button" aria-label="Dismiss notification" onclick={() => onDismiss?.(item.id)}>×</button>
        </div>
    {/each}
</section>
