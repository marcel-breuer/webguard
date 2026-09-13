<script lang="ts">
    import { onMount } from "svelte";
    import { FirstPartyApiError } from "$lib/api/client";
    import { formatDateTime } from "$lib/i18n/format";
    import type { MonitoringDetailData } from "$lib/api/monitoring";
    import Select from "$lib/components/Select.svelte";

    type ServerHealthTelemetry = NonNullable<MonitoringDetailData["server_health_telemetry"]>;
    type TelemetryPeriod = 1 | 7 | 30;
    type MetricState = "healthy" | "warning" | "critical" | "neutral";

    interface Props {
        detail: MonitoringDetailData;
        monitoringId: string;
    }

    const fallbackTelemetry: ServerHealthTelemetry = {
        data: [],
        thresholds: { cpu_usage_percent: 90, ram_usage_percent: 90, storage_usage_percent: 90, load_per_cpu: null },
    };
    let { detail, monitoringId }: Props = $props();
    let summaryTelemetry = $state<ServerHealthTelemetry>(fallbackTelemetry);
    let historyTelemetry = $state<ServerHealthTelemetry>(fallbackTelemetry);
    let initialized = $state(false);
    let summaryPeriod = $state<TelemetryPeriod>(1);
    let historyPeriod = $state<TelemetryPeriod>(1);
    let summaryLoading = $state(false);
    let historyLoading = $state(false);
    let error = $state("");
    let canvas = $state<HTMLCanvasElement | null>(null);
    let chart: { destroy: () => void } | null = null;

    const latest = $derived(summaryTelemetry.data.at(-1) ?? null);
    const hasChartData = $derived(historyTelemetry.data.some((point) => [
        point.cpu_usage_percent,
        point.ram_usage_percent,
        point.storage_usage_percent,
        point.normalized_load,
    ].some((value) => value !== null)));

    $effect(() => {
        if (initialized) return;

        summaryTelemetry = detail.server_health_telemetry ?? fallbackTelemetry;
        historyTelemetry = detail.server_health_telemetry ?? fallbackTelemetry;
        initialized = true;
    });

    function formatPercentage(value: number | null): string {
        return value === null ? "—" : `${value.toFixed(1)}%`;
    }

    function formatLoad(value: number | null): string {
        return value === null ? "—" : `${value.toFixed(2)}×`;
    }

    function timestamp(value: string | null): string {
        return formatDateTime(value, "—", {
            month: "short", day: "numeric", year: "numeric", hour: "2-digit", minute: "2-digit",
        });
    }

    function axisLabel(value: string): string {
        const date = new Date(value);

        if (Number.isNaN(date.getTime())) return "—";

        return historyPeriod === 1
            ? `${String(date.getHours()).padStart(2, "0")}:00`
            : `${String(date.getDate()).padStart(2, "0")}.${String(date.getMonth() + 1).padStart(2, "0")}`;
    }

    function metricState(value: number | null, threshold: number | null): MetricState {
        if (value === null || threshold === null || threshold <= 0) return "neutral";
        if (value >= threshold) return "critical";
        if (value >= threshold * 0.8) return "warning";

        return "healthy";
    }

    function cardTone(state: MetricState): string {
        if (state === "critical") return "border-red-200 dark:border-red-900/70";
        if (state === "warning") return "border-amber-200 dark:border-amber-900/70";
        if (state === "healthy") return "border-emerald-200 dark:border-emerald-900/70";

        return "border-wg-border";
    }

    function accentTone(state: MetricState): string {
        if (state === "critical") return "bg-red-500";
        if (state === "warning") return "bg-amber-500";
        if (state === "healthy") return "bg-emerald-500";

        return "bg-wg-text-muted";
    }

    function statusText(state: MetricState): string {
        if (state === "critical") return "Threshold reached";
        if (state === "warning") return "Near threshold";
        if (state === "healthy") return "Within limit";

        return "No threshold";
    }

    function chartColor(name: string, fallback: string): string {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }

    function chartFontFamily(): string {
        return getComputedStyle(document.body).fontFamily || "ui-sans-serif, system-ui, sans-serif";
    }

    function chartPointRadius(): number {
        return historyTelemetry.data.length === 1 ? 4 : 0;
    }

    async function renderChart(): Promise<void> {
        if (!canvas || !hasChartData) return;

        const { default: Chart } = await import("chart.js/auto");
        chart?.destroy();

        const muted = chartColor("--wg-text-muted", "#64748b");
        const border = chartColor("--wg-border", "#d9e0ea");
        const fontFamily = chartFontFamily();
        const pointRadius = chartPointRadius();
        chart = new Chart(canvas, {
            type: "line",
            data: {
                labels: historyTelemetry.data.map((point) => axisLabel(point.checked_at)),
                datasets: [
                    { label: "CPU", data: historyTelemetry.data.map((point) => point.cpu_usage_percent), borderColor: "#8b5cf6", backgroundColor: "#8b5cf6", borderWidth: 2.5, pointRadius, pointHoverRadius: 4, tension: 0.35 },
                    { label: "RAM", data: historyTelemetry.data.map((point) => point.ram_usage_percent), borderColor: "#0ea5e9", backgroundColor: "#0ea5e9", borderWidth: 2.5, pointRadius, pointHoverRadius: 4, tension: 0.35 },
                    { label: "Storage", data: historyTelemetry.data.map((point) => point.storage_usage_percent), borderColor: "#10b981", backgroundColor: "#10b981", borderWidth: 2.5, pointRadius, pointHoverRadius: 4, tension: 0.35 },
                    { label: "Load", data: historyTelemetry.data.map((point) => point.normalized_load), borderColor: "#f59e0b", backgroundColor: "#f59e0b", borderWidth: 2, borderDash: [5, 4], pointRadius, pointHoverRadius: 4, tension: 0.35, yAxisID: "load" },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { position: "top", labels: { color: muted, usePointStyle: true, pointStyle: "circle", boxWidth: 7, boxHeight: 7, padding: 20, font: { family: fontFamily, size: 12, weight: 600 } } },
                    tooltip: {
                        backgroundColor: chartColor("--wg-text", "#172033"),
                        titleColor: "#ffffff",
                        bodyColor: "#ffffff",
                        padding: 12,
                        callbacks: {
                            title: (contexts) => timestamp(historyTelemetry.data[contexts[0]?.dataIndex]?.checked_at ?? null),
                            label: (context) => context.dataset.label === "Load"
                                ? `Load: ${formatLoad(Number(context.parsed.y))}`
                                : `${context.dataset.label}: ${formatPercentage(Number(context.parsed.y))}`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: muted, maxTicksLimit: 6, font: { family: fontFamily, size: 11, weight: 600 } } },
                    y: { min: 0, max: 100, title: { display: true, text: "Utilization (%)", color: muted, font: { family: fontFamily, size: 11, weight: 600 } }, grid: { color: border }, border: { display: false }, ticks: { color: muted, font: { family: fontFamily, size: 11, weight: 600 } } },
                    load: { min: 0, position: "right", title: { display: true, text: "Load per CPU", color: muted, font: { family: fontFamily, size: 11, weight: 600 } }, grid: { drawOnChartArea: false }, border: { display: false }, ticks: { color: muted, font: { family: fontFamily, size: 11, weight: 600 } } },
                },
            },
        });
    }

    $effect(() => {
        if (!canvas || !hasChartData) {
            chart?.destroy();
            chart = null;

            return;
        }

        void renderChart();
    });

    async function requestTelemetry(requestedPeriod: TelemetryPeriod): Promise<ServerHealthTelemetry> {
        const response = await fetch(
            `/api/monitorings/${encodeURIComponent(monitoringId)}/server-health-telemetry?days=${requestedPeriod}`,
            {
                credentials: "same-origin",
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            },
        );

        if (!response.ok) {
            throw new FirstPartyApiError(response.status, await response.json().catch(() => ({})));
        }

        return await response.json() as ServerHealthTelemetry;
    }

    function telemetryError(exception: unknown): string {
        return exception instanceof FirstPartyApiError ? exception.message : "Server-health telemetry could not be loaded.";
    }

    async function loadSummaryTelemetry(requestedPeriod: TelemetryPeriod): Promise<void> {
        if (summaryLoading || requestedPeriod === summaryPeriod) return;

        summaryLoading = true;
        error = "";

        try {
            summaryTelemetry = await requestTelemetry(requestedPeriod);
            summaryPeriod = requestedPeriod;
        } catch (exception) {
            error = telemetryError(exception);
        } finally {
            summaryLoading = false;
        }
    }

    async function loadHistoryTelemetry(requestedPeriod: TelemetryPeriod): Promise<void> {
        if (historyLoading || requestedPeriod === historyPeriod) return;

        historyLoading = true;
        error = "";

        try {
            historyTelemetry = await requestTelemetry(requestedPeriod);
            historyPeriod = requestedPeriod;
        } catch (exception) {
            error = telemetryError(exception);
        } finally {
            historyLoading = false;
        }
    }

    async function loadInitialTelemetry(): Promise<void> {
        summaryLoading = true;
        historyLoading = true;
        error = "";

        try {
            const telemetry = await requestTelemetry(1);
            summaryTelemetry = telemetry;
            historyTelemetry = telemetry;
            summaryPeriod = 1;
            historyPeriod = 1;
        } catch (exception) {
            error = telemetryError(exception);
        } finally {
            summaryLoading = false;
            historyLoading = false;
        }
    }

    async function changeSummaryPeriod(event: Event): Promise<void> {
        await loadSummaryTelemetry(Number((event.currentTarget as HTMLSelectElement).value) as TelemetryPeriod);
    }

    async function changeHistoryPeriod(event: Event): Promise<void> {
        await loadHistoryTelemetry(Number((event.currentTarget as HTMLSelectElement).value) as TelemetryPeriod);
    }

    onMount(() => {
        void loadInitialTelemetry();
        const observer = new MutationObserver(() => void renderChart());
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });

        return () => {
            observer.disconnect();
            chart?.destroy();
        };
    });
</script>

<section class="mt-6" aria-labelledby="server-health-heading">
    <div class="relative overflow-hidden rounded-2xl border border-wg-border bg-wg-surface px-5 py-5 shadow-sm sm:px-6">
        <div class="absolute inset-y-0 left-0 w-1 bg-wg-accent"></div>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-wg-accent/10" aria-hidden="true"><span class="size-2.5 rounded-full bg-wg-accent ring-4 ring-wg-accent/15"></span></span>
                <div><p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-accent uppercase">Live server metrics</p><h2 id="server-health-heading" class="mt-0.5 text-2xl font-extrabold tracking-tight">Server health</h2></div>
            </div>
            <div class="flex flex-wrap items-center gap-2"><p class="w-fit rounded-full bg-wg-surface-muted px-3 py-1.5 text-sm font-medium text-wg-text-muted">{latest ? `Last report ${timestamp(latest.checked_at)}` : "Waiting for the first server report"}</p><Select width="compact" density="compact" value={String(summaryPeriod)} onchange={changeSummaryPeriod} disabled={summaryLoading} class="font-semibold text-wg-text-muted" aria-label="Server health metrics period"><option value="1">Last 24 hours</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option></Select></div>
        </div>
    </div>

    {#if latest}
        <dl class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class={`group relative overflow-hidden rounded-2xl border bg-wg-surface p-5 shadow-sm transition-shadow hover:shadow-md ${cardTone(metricState(latest.cpu_usage_percent, summaryTelemetry.thresholds.cpu_usage_percent))}`}><span class={`absolute inset-x-0 top-0 h-1 ${accentTone(metricState(latest.cpu_usage_percent, summaryTelemetry.thresholds.cpu_usage_percent))}`}></span><dt class="flex items-center justify-between text-[0.6875rem] font-extrabold tracking-[0.14em] text-wg-text-muted uppercase">CPU <span class={`size-2 rounded-full ${accentTone(metricState(latest.cpu_usage_percent, summaryTelemetry.thresholds.cpu_usage_percent))}`} title={statusText(metricState(latest.cpu_usage_percent, summaryTelemetry.thresholds.cpu_usage_percent))}></span></dt><dd class="mt-5 text-4xl leading-none font-extrabold tracking-tight tabular-nums">{formatPercentage(latest.cpu_usage_percent)}</dd><dd class="mt-5 flex items-center justify-between border-t border-wg-border/70 pt-3 text-sm text-wg-text-muted"><span>Limit</span><span class="font-bold text-wg-text">{formatPercentage(summaryTelemetry.thresholds.cpu_usage_percent)}</span></dd></div>
            <div class={`group relative overflow-hidden rounded-2xl border bg-wg-surface p-5 shadow-sm transition-shadow hover:shadow-md ${cardTone(metricState(latest.ram_usage_percent, summaryTelemetry.thresholds.ram_usage_percent))}`}><span class={`absolute inset-x-0 top-0 h-1 ${accentTone(metricState(latest.ram_usage_percent, summaryTelemetry.thresholds.ram_usage_percent))}`}></span><dt class="flex items-center justify-between text-[0.6875rem] font-extrabold tracking-[0.14em] text-wg-text-muted uppercase">RAM <span class={`size-2 rounded-full ${accentTone(metricState(latest.ram_usage_percent, summaryTelemetry.thresholds.ram_usage_percent))}`} title={statusText(metricState(latest.ram_usage_percent, summaryTelemetry.thresholds.ram_usage_percent))}></span></dt><dd class="mt-5 text-4xl leading-none font-extrabold tracking-tight tabular-nums">{formatPercentage(latest.ram_usage_percent)}</dd><dd class="mt-5 flex items-center justify-between border-t border-wg-border/70 pt-3 text-sm text-wg-text-muted"><span>Limit</span><span class="font-bold text-wg-text">{formatPercentage(summaryTelemetry.thresholds.ram_usage_percent)}</span></dd></div>
            <div class={`group relative overflow-hidden rounded-2xl border bg-wg-surface p-5 shadow-sm transition-shadow hover:shadow-md ${cardTone(metricState(latest.storage_usage_percent, summaryTelemetry.thresholds.storage_usage_percent))}`}><span class={`absolute inset-x-0 top-0 h-1 ${accentTone(metricState(latest.storage_usage_percent, summaryTelemetry.thresholds.storage_usage_percent))}`}></span><dt class="flex items-center justify-between text-[0.6875rem] font-extrabold tracking-[0.14em] text-wg-text-muted uppercase">Storage <span class={`size-2 rounded-full ${accentTone(metricState(latest.storage_usage_percent, summaryTelemetry.thresholds.storage_usage_percent))}`} title={statusText(metricState(latest.storage_usage_percent, summaryTelemetry.thresholds.storage_usage_percent))}></span></dt><dd class="mt-5 text-4xl leading-none font-extrabold tracking-tight tabular-nums">{formatPercentage(latest.storage_usage_percent)}</dd><dd class="mt-5 flex items-center justify-between border-t border-wg-border/70 pt-3 text-sm text-wg-text-muted"><span>Limit</span><span class="font-bold text-wg-text">{formatPercentage(summaryTelemetry.thresholds.storage_usage_percent)}</span></dd></div>
            <div class={`group relative overflow-hidden rounded-2xl border bg-wg-surface p-5 shadow-sm transition-shadow hover:shadow-md ${cardTone(metricState(latest.normalized_load, summaryTelemetry.thresholds.load_per_cpu))}`}><span class={`absolute inset-x-0 top-0 h-1 ${accentTone(metricState(latest.normalized_load, summaryTelemetry.thresholds.load_per_cpu))}`}></span><dt class="flex items-center justify-between text-[0.6875rem] font-extrabold tracking-[0.14em] text-wg-text-muted uppercase">Load per CPU <span class={`size-2 rounded-full ${accentTone(metricState(latest.normalized_load, summaryTelemetry.thresholds.load_per_cpu))}`} title={statusText(metricState(latest.normalized_load, summaryTelemetry.thresholds.load_per_cpu))}></span></dt><dd class="mt-5 text-4xl leading-none font-extrabold tracking-tight tabular-nums">{formatLoad(latest.normalized_load)}</dd><dd class="mt-5 flex items-center justify-between border-t border-wg-border/70 pt-3 text-sm text-wg-text-muted"><span>Limit</span><span class="font-bold text-wg-text">{summaryTelemetry.thresholds.load_per_cpu === null ? "—" : formatLoad(summaryTelemetry.thresholds.load_per_cpu)}</span></dd></div>
        </dl>
    {:else}
        <div class="mt-4 rounded-2xl border border-wg-border bg-wg-surface p-6 text-sm leading-6 text-wg-text-muted shadow-sm">Server metrics will appear after the monitoring receives its first report.</div>
    {/if}

    <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-[0.6875rem] font-extrabold tracking-[0.14em] text-wg-text-muted uppercase">Telemetry trend</p><h3 class="mt-0.5 text-xl font-extrabold tracking-tight">Utilization history</h3></div><Select width="compact" density="compact" value={String(historyPeriod)} onchange={changeHistoryPeriod} disabled={historyLoading} class="font-semibold text-wg-text-muted" aria-label="Server health history period"><option value="1">Last 24 hours</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option></Select></div>
    <article class="mt-3 overflow-hidden rounded-2xl border border-wg-border bg-wg-surface shadow-sm">
        <div class="flex items-center justify-between gap-3 border-b border-wg-border bg-wg-surface-muted/50 px-5 py-3 text-xs font-bold text-wg-text-muted"><span>CPU · RAM · Storage · Load</span><span class="flex items-center gap-2"><span class="size-2 rounded-full bg-emerald-500"></span>{historyLoading ? "Updating" : "Reported values"}</span></div>
        {#if hasChartData}<div class="h-64 p-4 sm:h-96 sm:p-6" role="img" aria-label="Server CPU RAM storage and load utilization history"><canvas bind:this={canvas}></canvas></div>
        {:else}<p class="p-6 text-sm leading-6 text-wg-text-muted">{historyLoading ? "Loading server-health telemetry…" : "Server-health history will appear after the monitoring collects reports."}</p>{/if}
    </article>
    {#if error}<p class="mt-3 text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}
</section>
