<script lang="ts">
	import { Select as SelectPrimitive } from "bits-ui";
	import ChevronDownIcon from '@lucide/svelte/icons/chevron-down';
	import { cn, type WithoutChild } from "#lib/utils.ts";

	let {
		ref = $bindable(null),
		class: className,
		children,
		size = "default",
		...restProps
	}: WithoutChild<SelectPrimitive.TriggerProps> & {
		size?: "sm" | "default";
	} = $props();
</script>

<SelectPrimitive.Trigger
	bind:ref
	data-slot="select-trigger"
	data-size={size}
	class={cn(
		"wg-glass-control flex w-fit items-center justify-between gap-1.5 whitespace-nowrap border border-wg-border text-wg-text transition-colors data-placeholder:text-wg-text-muted hover:border-wg-text-muted focus:border-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus data-[size=default]:min-h-11 data-[size=default]:px-3 data-[size=default]:py-2 data-[size=default]:text-sm data-[size=sm]:min-h-8 data-[size=sm]:px-2.5 data-[size=sm]:py-1 data-[size=sm]:text-xs disabled:cursor-not-allowed disabled:bg-wg-surface-muted disabled:text-wg-text-muted *:data-[slot=select-value]:line-clamp-1 [&_svg]:shrink-0",
		className
	)}
	{...restProps}
>
	{@render children?.()}
	<ChevronDownIcon class="text-wg-accent size-4 pointer-events-none" />
</SelectPrimitive.Trigger>
