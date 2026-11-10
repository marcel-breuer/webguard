<script lang="ts">
    import type { Snippet } from "svelte";
    import type { HTMLButtonAttributes } from "svelte/elements";

    type Variant = "primary" | "secondary" | "danger" | "quiet";

    interface Props extends HTMLButtonAttributes {
        variant?: Variant;
        loading?: boolean;
        children?: Snippet;
    }

    let {
        variant = "primary",
        loading = false,
        disabled = false,
        children,
        class: className = "",
        ...attributes
    }: Props = $props();

    const variants: Record<Variant, string> = {
        primary: "border-wg-accent bg-wg-accent text-wg-accent-contrast shadow-sm enabled:hover:bg-wg-accent-strong enabled:hover:shadow-md",
        secondary: "border-wg-accent bg-transparent text-wg-accent enabled:hover:bg-violet-50 dark:enabled:hover:bg-violet-950/40",
        danger: "border-wg-danger bg-wg-danger text-white dark:text-slate-950 enabled:hover:brightness-95",
        quiet: "text-wg-text enabled:hover:border-wg-focus enabled:hover:bg-wg-surface-muted",
    };
</script>

<button
    {...attributes}
    class={`inline-flex min-h-11 items-center justify-center gap-2 rounded-md border px-4 py-2.5 text-sm leading-5 font-semibold tracking-[0.035em] transition-[background-color,border-color,box-shadow,color,transform] duration-150 ease-out active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:opacity-60 ${variants[variant]} ${className}`}
    disabled={disabled || loading}
    aria-busy={loading}
>
    {#if loading}
        <span class="size-3.5 animate-spin rounded-full border-2 border-current border-r-transparent" aria-hidden="true"></span>
        <span class="sr-only">Saving</span>
    {/if}
    {@render children?.()}
</button>
