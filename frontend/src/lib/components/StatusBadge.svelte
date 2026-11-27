<script lang="ts">
    import CircleCheckIcon from "@lucide/svelte/icons/circle-check";
    import CircleHelpIcon from "@lucide/svelte/icons/circle-help";
    import CirclePauseIcon from "@lucide/svelte/icons/circle-pause";
    import CircleXIcon from "@lucide/svelte/icons/circle-x";
    import TriangleAlertIcon from "@lucide/svelte/icons/triangle-alert";
    import WrenchIcon from "@lucide/svelte/icons/wrench";
    import { Badge } from "#lib/components/ui/badge/index.ts";
    export type StatusTone = "healthy" | "degraded" | "danger" | "neutral" | "paused" | "maintenance";

    interface Props { tone?: StatusTone; label: string; }
    let { tone = "neutral", label }: Props = $props();
    const variants = { healthy: "healthy", degraded: "degraded", danger: "danger", neutral: "neutral", paused: "paused", maintenance: "neutral" } as const;
</script>

<Badge variant={variants[tone]} class="wg-status-badge" data-tone={tone}>
    {#if tone === "healthy"}<CircleCheckIcon class="size-3.5" aria-hidden="true" />
    {:else if tone === "degraded"}<TriangleAlertIcon class="size-3.5" aria-hidden="true" />
    {:else if tone === "danger"}<CircleXIcon class="size-3.5" aria-hidden="true" />
    {:else if tone === "maintenance"}<WrenchIcon class="size-3.5" aria-hidden="true" />
    {:else if tone === "paused"}<CirclePauseIcon class="size-3.5" aria-hidden="true" />
    {:else}<CircleHelpIcon class="size-3.5" aria-hidden="true" />{/if}
    {label}
</Badge>
