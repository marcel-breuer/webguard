<script lang="ts">
	import { cn, type WithElementRef } from "#lib/utils.ts";
	import type { HTMLAttributes } from "svelte/elements";

	let {
		ref = $bindable(null),
		variant = "default",
		class: className,
		children,
		...restProps
	}: WithElementRef<HTMLAttributes<HTMLElement>> & { variant?: "default" | "bar" } = $props();
</script>

<svelte:element this={variant === "bar" ? "header" : "div"}
	bind:this={ref}
	data-slot="sidebar-header"
	data-sidebar="header"
	class={cn(variant === "bar" ? "wg-glass-bar border-b" : "gap-2 p-2 flex flex-col", className)}
	{...restProps}
>
	{@render children?.()}
</svelte:element>
