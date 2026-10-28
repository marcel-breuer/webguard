<script lang="ts">
    import type { MonitoringDetailData } from "#lib/api/monitoring.ts";
    import Card from "#lib/components/Card.svelte";
    import { formatNumber, formatPercentage as formatLocalizedPercentage } from "#lib/i18n/format.ts";

    interface Props { detail: MonitoringDetailData; }
    let { detail }: Props = $props();
    function percentage(value: number | null): string {
        return value === null ? "—" : formatLocalizedPercentage(value, 1);
    }

    function load(value: number | null): string {
        return value === null ? "No limit configured" : `Limit ${formatNumber(value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}×`;
    }
</script>

{#if detail.server_health_telemetry}
    <section class="mt-6"><Card title="Monitoring diagnostics" description="Configured server-health alert thresholds."><dl class="grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4"><div><dt class="text-wg-text-muted">CPU alert</dt><dd class="mt-1 font-bold">Limit {percentage(detail.server_health_telemetry.thresholds.cpu_usage_percent)}</dd></div><div><dt class="text-wg-text-muted">RAM alert</dt><dd class="mt-1 font-bold">Limit {percentage(detail.server_health_telemetry.thresholds.ram_usage_percent)}</dd></div><div><dt class="text-wg-text-muted">Storage alert</dt><dd class="mt-1 font-bold">Limit {percentage(detail.server_health_telemetry.thresholds.storage_usage_percent)}</dd></div><div><dt class="text-wg-text-muted">Load alert</dt><dd class="mt-1 font-bold">{load(detail.server_health_telemetry.thresholds.load_per_cpu)}</dd></div></dl></Card></section>
{/if}
