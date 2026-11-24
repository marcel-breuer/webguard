<script lang="ts">
    import { Badge } from "#lib/components/ui/badge/index.ts";
    export type StatusTone = "healthy" | "degraded" | "danger" | "neutral" | "paused";

    interface Props { tone?: StatusTone; label: string; }
    let { tone = "neutral", label }: Props = $props();
</script>

<Badge variant={tone}>
    <span class="size-[0.45rem] rounded-full bg-current" aria-hidden="true"></span>{label}
</Badge>
