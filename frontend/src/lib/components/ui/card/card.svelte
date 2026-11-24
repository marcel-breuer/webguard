<script lang="ts">
	import { cn, type WithElementRef } from "#lib/utils.ts";
	import type { HTMLAttributes } from "svelte/elements";

	let {
		ref = $bindable(null),
		class: className,
		children,
		as = "div",
		size = "default",
		...restProps
	}: WithElementRef<HTMLAttributes<HTMLElement>> & { as?: "div" | "section"; size?: "default" | "sm" } = $props();
</script>

<svelte:element this={as}
	bind:this={ref}
	data-slot="card"
	data-size={size}
	class={cn("wg-glass-panel overflow-hidden", className)}
	{...restProps}
>
	{@render children?.()}
</svelte:element>
