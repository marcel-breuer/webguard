<script lang="ts">
    import { Skeleton } from "#lib/components/ui/skeleton/index.ts";

    interface Props { label?: string; }
    let { label = "Loading" }: Props = $props();
</script>

<div class="grid gap-3" role="status" aria-busy="true" aria-label={label}>
    <span class="text-sm font-medium text-wg-text-muted">{label}</span>
    <div class="grid gap-2" aria-hidden="true">
        <Skeleton class="h-4 w-3/4 animate-none rounded-sm" />
        <Skeleton class="h-4 w-full animate-none rounded-sm" />
        <Skeleton class="h-4 w-2/3 animate-none rounded-sm" />
    </div>
</div>
