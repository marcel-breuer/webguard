<script lang="ts">
    import type { Snippet } from "svelte";
    interface Props { caption: string; children?: Snippet; }
    let { caption, children }: Props = $props();
</script>
<div role="region" aria-label={caption} class="overflow-x-auto overscroll-x-contain wg-glass-inset [-webkit-overflow-scrolling:touch] [&_caption]:sr-only [&_table]:min-w-[42rem] [&_table]:border-collapse [&_table]:text-left sm:[&_table]:min-w-full [&_table]:w-full [&_tbody_tr:last-child_td]:border-b-0 [&_td]:border-b [&_td]:border-wg-border [&_td]:px-4 [&_td]:py-3.5 [&_td]:align-middle [&_th]:border-b [&_th]:border-wg-border [&_th]:bg-wg-surface-muted [&_th]:px-4 [&_th]:py-3.5 [&_th]:align-middle [&_th]:text-xs [&_th]:tracking-[0.06em] [&_th]:text-wg-text-muted [&_th]:uppercase"><table><caption>{caption}</caption>{@render children?.()}</table></div>
