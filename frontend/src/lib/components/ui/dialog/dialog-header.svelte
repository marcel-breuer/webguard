<script lang="ts">
	import { cn, type WithElementRef } from "#lib/utils.ts";
	import type { HTMLAttributes } from "svelte/elements";

	let {
		ref = $bindable(null),
		class: className,
		children,
		...restProps
	}: WithElementRef<HTMLAttributes<HTMLDivElement>> = $props();
</script>

<div
	bind:this={ref}
	data-slot="dialog-header"
	class={cn("flex items-start justify-between gap-3 border-b border-wg-border px-4 py-4 sm:gap-4 sm:px-6 sm:py-5", className)}
	{...restProps}
>
	{@render children?.()}
</div>
