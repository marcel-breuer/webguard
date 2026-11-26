<script lang="ts">
	import { cn, type WithElementRef } from "#lib/utils.ts";
	import type { HTMLAttributes } from "svelte/elements";

	let {
		ref = $bindable(null),
		class: className,
		children,
		as = "div",
		size = "default",
		variant = "default",
		...restProps
	}: WithElementRef<HTMLAttributes<HTMLElement>> & { as?: "div" | "section"; size?: "default" | "sm"; variant?: "default" | "inset" } = $props();
</script>

<svelte:element this={as}
	bind:this={ref}
	data-slot="card"
	data-size={size}
	data-variant={variant}
	class={cn(variant === "inset" ? "wg-glass-inset" : "wg-glass-panel", "overflow-hidden", className)}
	{...restProps}
>
	{@render children?.()}
</svelte:element>
