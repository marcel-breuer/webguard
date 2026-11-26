<script lang="ts">
    import { Badge } from "#lib/components/ui/badge/index.ts";
    export type StatusTone = "healthy" | "degraded" | "danger" | "neutral" | "paused";

    interface Props { tone?: StatusTone; label: string; }
    let { tone = "neutral", label }: Props = $props();
</script>

<Badge variant={tone}>
    <Badge variant="dot" class="size-[0.45rem]" aria-hidden="true" />{label}
</Badge>
