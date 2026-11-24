<script lang="ts">
	import { Dialog as DialogPrimitive } from "bits-ui";
	import XIcon from '@lucide/svelte/icons/x';
	import { Button } from "#lib/components/ui/button/index.ts";
	import { cn, type WithoutChildrenOrChild } from "#lib/utils.ts";
	import DialogOverlay from "./dialog-overlay.svelte";
	import DialogPortal from "./dialog-portal.svelte";
	import type { Snippet } from "svelte";
	import type { ComponentProps } from "svelte";

	let {
		ref = $bindable(null),
		class: className,
		portalProps,
		size = "default",
		layer = "default",
		children,
		showCloseButton = true,
		...restProps
	}: WithoutChildrenOrChild<DialogPrimitive.ContentProps> & {
		portalProps?: WithoutChildrenOrChild<ComponentProps<typeof DialogPortal>>;
		children: Snippet;
		showCloseButton?: boolean;
		size?: "default" | "wide";
		layer?: "default" | "overlay";
	} = $props();
</script>

<DialogPortal {...portalProps}>
	<DialogOverlay class={layer === "overlay" ? "z-[60]" : "z-50"} />
	<DialogPrimitive.Content
		bind:ref
		data-slot="dialog-content"
		class={cn(
			"fixed top-1/2 left-1/2 grid max-h-[calc(100dvh-1rem)] w-[calc(100%-1rem)] -translate-x-1/2 -translate-y-1/2 grid-rows-[auto_minmax(0,1fr)] overflow-hidden wg-glass-panel wg-glass-overlay wg-glass-dialog text-wg-text outline-none sm:max-h-[calc(100dvh-2rem)] sm:w-[calc(100%-2rem)]",
			size === "wide" ? "max-w-5xl" : "max-w-2xl",
			layer === "overlay" ? "z-[61]" : "z-51",
			className
		)}
		{...restProps}
	>
		{@render children?.()}
		{#if showCloseButton}
			<DialogPrimitive.Close data-slot="dialog-close">
				{#snippet child({ props })}
					<Button variant="ghost" class="absolute top-2 right-2" size="icon-sm" {...props}>
						<XIcon  />
						<span class="sr-only">Close</span>
					</Button>
				{/snippet}
			</DialogPrimitive.Close>
		{/if}
	</DialogPrimitive.Content>
</DialogPortal>
