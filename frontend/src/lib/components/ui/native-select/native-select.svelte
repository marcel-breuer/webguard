<script lang="ts">
	import ChevronDownIcon from '@lucide/svelte/icons/chevron-down';
	import { cn, type WithElementRef } from "#lib/utils.ts";
	import type { HTMLSelectAttributes } from "svelte/elements";

	type NativeSelectProps = WithElementRef<HTMLSelectAttributes, HTMLSelectElement> & {
		density?: "compact" | "default";
		wrapperClass?: string;
	};

	let {
		ref = $bindable(null),
		value = $bindable(),
		class: className,
		density = "default",
		wrapperClass,
		multiple = false,
		children,
		...restProps
	}: NativeSelectProps = $props();

	const controlClass = $derived(cn(
		"wg-glass-control w-full appearance-none border border-wg-border text-wg-text outline-none transition-colors hover:border-wg-text-muted focus:border-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:bg-wg-surface-muted disabled:text-wg-text-muted",
		density === "compact" ? "min-h-8 px-2.5 py-1 text-xs" : "min-h-11 px-3 py-2 text-sm",
		multiple ? "pr-3" : "pr-10",
		className,
	));
</script>

<div class={cn("relative w-fit", wrapperClass)} data-slot="native-select-wrapper">
	{#if multiple}
		<select {...restProps} bind:value bind:this={ref} multiple data-slot="native-select" class={controlClass}>
			{@render children?.()}
		</select>
	{:else}
		<select {...restProps} bind:value bind:this={ref} data-slot="native-select" class={controlClass}>
			{@render children?.()}
		</select>
		<ChevronDownIcon class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-wg-accent" aria-hidden="true" data-slot="native-select-icon" />
	{/if}
</div>
