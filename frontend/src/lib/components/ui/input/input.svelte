<script lang="ts">
	import { cn, type WithElementRef } from "#lib/utils.ts";
	import type { HTMLInputAttributes } from "svelte/elements";

	type Props = WithElementRef<HTMLInputAttributes & { files?: FileList | null }>;

	let {
		ref = $bindable(null),
		value = $bindable(),
		type,
		files = $bindable(),
		class: className,
		"data-slot": dataSlot = "input",
		...restProps
	}: Props = $props();
</script>

{#if type === "file"}
	<input
		bind:this={ref}
		data-slot={dataSlot}
		class={cn(
			"min-h-11 w-full wg-glass-control border border-wg-border px-3 py-2 text-sm text-wg-text transition-colors placeholder:text-wg-text-muted focus:border-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:bg-wg-surface-muted disabled:text-wg-text-muted read-only:bg-wg-surface-muted",
			className
		)}
		type="file"
		bind:files
		bind:value
		{...restProps}
	/>
{:else}
	<input
		bind:this={ref}
		data-slot={dataSlot}
		class={cn(
			"min-h-11 w-full wg-glass-control border border-wg-border px-3 py-2 text-sm text-wg-text transition-colors placeholder:text-wg-text-muted focus:border-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:bg-wg-surface-muted disabled:text-wg-text-muted read-only:bg-wg-surface-muted",
			className
		)}
		{type}
		bind:value
		{...restProps}
	/>
{/if}
