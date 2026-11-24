<script lang="ts">
	import { Label as LabelPrimitive } from "bits-ui";
	import { cn } from "#lib/utils.ts";

	let {
		ref = $bindable(null),
		class: className,
		...restProps
	}: LabelPrimitive.RootProps = $props();
</script>

<LabelPrimitive.Root
	bind:ref
	data-slot="label"
	class={cn(
		"grid gap-[0.45rem] text-wg-text",
		className
	)}
	{...restProps}
/>
