<script lang="ts">
    import { Spinner } from "#lib/components/ui/spinner/index.ts";
    import { Button as ButtonPrimitive, type ButtonVariant } from "#lib/components/ui/button/index.ts";
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
        type = "submit",
        children,
        class: className = "",
        ...attributes
    }: Props = $props();

    const variants: Record<Variant, ButtonVariant> = {
        primary: "default",
        secondary: "outline",
        danger: "destructive",
        quiet: "ghost",
    };
</script>

<ButtonPrimitive
    {...(attributes as Record<string, unknown>)}
    {type}
    variant={variants[variant]}
    class={className}
    disabled={disabled || loading}
    aria-busy={loading}
>
    {#if loading}
        <Spinner class="size-3.5" aria-hidden="true" />
        <span class="sr-only">Saving</span>
    {/if}
    {@render children?.()}
</ButtonPrimitive>
