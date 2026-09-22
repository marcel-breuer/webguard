<script lang="ts">
    import type { Snippet } from "svelte";

    interface Props {
        title?: string;
        titleId?: string;
        description?: string;
        actions?: Snippet;
        children?: Snippet;
    }

    let { title, titleId, description, actions, children }: Props = $props();
</script>

<section class="overflow-hidden rounded-2xl border border-wg-border bg-wg-surface shadow-wg-surface">
    {#if title || description || actions}
        <header class="flex flex-col items-start justify-between gap-4 border-b border-wg-border px-[1.125rem] py-[1.125rem] sm:flex-row sm:px-6 sm:py-5">
            <div>
                {#if title}<h2 id={titleId} class="text-lg leading-6 font-bold">{title}</h2>{/if}
                {#if description}<p class="mt-[0.35rem] text-sm leading-[1.35rem] text-wg-text-muted">{description}</p>{/if}
            </div>
            {#if actions}<div class="shrink-0">{@render actions()}</div>{/if}
        </header>
    {/if}
    <div class="p-[1.125rem] sm:p-6">{@render children?.()}</div>
</section>
