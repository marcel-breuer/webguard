<script lang="ts">
    import { Spinner } from "#lib/components/ui/spinner/index.ts";

    interface Props { label?: string; }
    let { label = "Loading" }: Props = $props();
</script>

<div class="inline-flex items-center gap-[0.65rem] text-sm font-bold text-wg-text-muted" role="status">
    <Spinner class="text-wg-accent" aria-hidden="true" />
    <span>{label}</span>
</div>
