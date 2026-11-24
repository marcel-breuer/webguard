<script lang="ts">
	import { cn, type WithElementRef } from "#lib/utils.ts";
	import type { HTMLAttributes } from "svelte/elements";

	let {
		ref = $bindable(null),
		class: className,
		children,
		...restProps
	}: WithElementRef<HTMLAttributes<HTMLElement>> = $props();
</script>

<header
	bind:this={ref}
	data-slot="card-header"
	class={cn(
		"flex flex-col items-start justify-between gap-4 border-b border-wg-border px-[1.125rem] py-[1.125rem] sm:flex-row sm:px-6 sm:py-5",
		className
	)}
	{...restProps}
>
	{@render children?.()}
</header>
