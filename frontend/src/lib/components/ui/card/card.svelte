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
	}: WithElementRef<HTMLAttributes<HTMLElement>> & { as?: "div" | "section" | "article" | "li"; size?: "default" | "sm"; variant?: "default" | "inset" | "overlay" | "toolbar" | "sticky-footer" } = $props();

	const surfaces = {
		default: "wg-glass-panel",
		inset: "wg-glass-inset",
		toolbar: "wg-glass-bar rounded-none border-b",
		"sticky-footer": "wg-glass-bar rounded-none border-t",
		overlay: "wg-glass-panel wg-glass-overlay wg-glass-dialog",
	};
</script>

<svelte:element this={as}
	bind:this={ref}
	data-slot="card"
	data-size={size}
	data-variant={variant}
	class={cn(surfaces[variant], "overflow-hidden", className)}
	{...restProps}
>
	{@render children?.()}
</svelte:element>
