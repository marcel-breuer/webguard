<script lang="ts">
    import { onMount, tick } from "svelte";
    import { FirstPartyApiError, requestFirstPartyApi } from "$lib/api/client";
    import { formatDateTime, formatMonthYear, formatNumber, formatPercentage } from "$lib/i18n/format";
    import type { MonitoringDetailData, MonitoringDetailMeta } from "$lib/api/monitoring";
    import Button from "$lib/components/Button.svelte";
    import Select from "$lib/components/Select.svelte";

    type Incident = MonitoringDetailData["incidents"][number];
    type RecentCheck = MonitoringDetailData["recent_checks"][number];
    type ResponseTimePeriod = 1 | 7 | 30;

    interface Props {
        detail: MonitoringDetailData;
        monitoringId: string;
        incidentsMeta: MonitoringDetailMeta["incidents"];
        recentChecksMeta: MonitoringDetailMeta["recent_checks"];
        uptimeCalendarMeta: MonitoringDetailMeta["uptime_calendar"];
    }

    let { detail, monitoringId, incidentsMeta, recentChecksMeta, uptimeCalendarMeta }: Props = $props();
    let incidents = $state<Incident[]>([]);
    let currentIncidentsMeta = $state<MonitoringDetailMeta["incidents"]>({ limit: 5, offset: 0, has_more: false, next_offset: null });
    let loadingIncidents = $state(false);
    let incidentsError = $state("");
    let checks = $state<RecentCheck[]>([]);
    let checksMeta = $state<MonitoringDetailMeta["recent_checks"]>({ limit: 5, has_more: false, next_offset: null });
    let loadingChecks = $state(false);
    let checksError = $state("");
    let responseTimes = $state<MonitoringDetailData["response_times"]>({
        data: [],
        aggregated: { avg: null, min: null, max: null },
    });
    let responseTimeDays = $state<ResponseTimePeriod>(1);
    let loadingResponseTimes = $state(false);
    let responseTimesError = $state("");
    let responseTimeCanvas = $state<HTMLCanvasElement | null>(null);
    let responseTimeChart: { destroy: () => void } | null = null;

    const points = $derived(responseTimes.data.filter((point) => point.avg !== null));
    let uptimeCalendar = $state<MonitoringDetailData["uptime_calendar"]>({});
    let loadingPreviousCalendarMonth = $state(false);
    let uptimeCalendarError = $state("");
    const calendarMonths = $derived(Object.entries(uptimeCalendar).sort(([left], [right]) => left.localeCompare(right)));
    let calendarCursor = $state<string | null>(null);
    const canLoadPreviousCalendarMonth = $derived(
        calendarCursor !== null
        && uptimeCalendarMeta.oldest_available_month !== null
        && calendarCursor > uptimeCalendarMeta.oldest_available_month,
    );

    $effect(() => {
        incidents = detail.incidents;
        currentIncidentsMeta = incidentsMeta;
        checks = detail.recent_checks;
        checksMeta = recentChecksMeta;
        responseTimes = detail.response_times;
        responseTimeDays = 1;
        uptimeCalendar = detail.uptime_calendar;
        calendarCursor = Object.keys(detail.uptime_calendar).sort()[0] ?? new Date().toISOString().slice(0, 7);
    });

    function timestamp(value: string | null): string {
        return formatDateTime(value, "—", {
            month: "2-digit", day: "2-digit", year: "numeric",
            hour: "2-digit", minute: "2-digit", second: "2-digit",
        });
    }

    function duration(incident: { down_at: string; up_at: string | null }): string {
        const end = incident.up_at ? new Date(incident.up_at) : new Date();
        const minutes = Math.max(0, Math.round((end.getTime() - new Date(incident.down_at).getTime()) / 60_000));
        return minutes >= 60 ? `${formatNumber(Math.floor(minutes / 60))}h ${formatNumber(minutes % 60)}m` : `${formatNumber(minutes)}m`;
    }

    function statusTone(status: RecentCheck["status"]): string {
        return status === "up" ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" : status === "down" ? "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300" : "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200";
    }

    function timelineDotTone(status: RecentCheck["status"]): string {
        return status === "up"
            ? "border-emerald-300 bg-emerald-50 text-emerald-600 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            : status === "down"
                ? "border-red-300 bg-red-50 text-red-600 dark:border-red-700 dark:bg-red-950 dark:text-red-300"
                : "border-amber-300 bg-amber-50 text-amber-600 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300";
    }

    function timelineConnectorTone(status: RecentCheck["status"]): string {
        return status === "up"
            ? "bg-emerald-200 dark:bg-emerald-800"
            : status === "down"
                ? "bg-red-200 dark:bg-red-800"
                : "bg-amber-200 dark:bg-amber-800";
    }

    function uptimeTone(uptime: number | null): string {
        if (uptime === null) return "bg-wg-surface-muted";
        if (uptime >= 97.5) return "bg-emerald-500";
        if (uptime >= 90) return "bg-amber-400";
        return "bg-red-500";
    }

    function calendarOffset(value: string): number[] {
        const day = new Date(value).getDay();
        return Array.from({ length: (day + 6) % 7 });
    }

    function calendarDayLabel(value: string): string {
        return formatDateTime(value, "—", {
            month: "short", day: "numeric", year: "numeric",
        });
    }

    function uptimePercentage(uptime: number | null): string {
        return uptime === null ? "No data" : formatPercentage(uptime);
    }

    function previousMonthRange(month: string): { startDate: string; endDate: string } {
        const year = Number(month.slice(0, 4));
        const monthNumber = Number(month.slice(5, 7));
        const previousMonthEnd = new Date(Date.UTC(year, monthNumber - 1, 0));
        const previousMonth = String(previousMonthEnd.getUTCMonth() + 1).padStart(2, "0");
        const previousYear = previousMonthEnd.getUTCFullYear();

        return {
            startDate: `${previousYear}-${previousMonth}-01`,
            endDate: previousMonthEnd.toISOString().slice(0, 10),
        };
    }

    function responseTimeAxisLabel(value: string): string {
        return formatDateTime(value, "—", responseTimeDays === 1
            ? { hour: "2-digit", minute: "2-digit" }
            : { day: "2-digit", month: "2-digit" });
    }

    async function loadPreviousCalendarMonth(): Promise<void> {
        if (loadingPreviousCalendarMonth || !canLoadPreviousCalendarMonth || calendarCursor === null) return;

        loadingPreviousCalendarMonth = true;
        uptimeCalendarError = "";
        const range = previousMonthRange(calendarCursor);

        try {
            const response = await fetch(
                `/api/monitorings/${encodeURIComponent(monitoringId)}/uptime-calendar?${new URLSearchParams({
                    start_date: range.startDate,
                    end_date: range.endDate,
                })}`,
                {
                    credentials: "same-origin",
                    headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                },
            );

            if (!response.ok) {
                throw new FirstPartyApiError(response.status, await response.json().catch(() => ({})));
            }

            uptimeCalendar = { ...(await response.json() as MonitoringDetailData["uptime_calendar"]), ...uptimeCalendar };
            calendarCursor = range.startDate.slice(0, 7);
        } catch (error) {
            uptimeCalendarError = error instanceof FirstPartyApiError ? error.message : "Previous uptime data could not be loaded.";
        } finally {
            loadingPreviousCalendarMonth = false;
        }
    }

    function chartColor(name: string, fallback: string): string {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }

    function chartFontFamily(): string {
        return getComputedStyle(document.body).fontFamily || "ui-sans-serif, system-ui, sans-serif";
    }

    async function renderResponseTimeChart(): Promise<void> {
        const canvas = responseTimeCanvas;
        if (!canvas || points.length === 0) return;

        const { default: Chart } = await import("chart.js/auto");
        responseTimeChart?.destroy();

        const accent = chartColor("--wg-accent", "#7e22ce");
        const muted = chartColor("--wg-text-muted", "#64748b");
        const border = chartColor("--wg-border", "#d9e0ea");
        const fontFamily = chartFontFamily();

        responseTimeChart = new Chart(canvas, {
            type: "line",
            data: {
                labels: points.map((point) => responseTimeAxisLabel(point.date)),
                datasets: [
                    { label: "Min. Response Time", data: points.map((point) => point.min ?? point.avg), borderColor: "#c4b5fd", borderWidth: 1.5, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 4, tension: 0.35 },
                    { label: "Avg. Response Time", data: points.map((point) => point.avg), borderColor: accent, backgroundColor: accent, borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 4, tension: 0.35 },
                    { label: "Max. Response Time", data: points.map((point) => point.max ?? point.avg), borderColor: "#d8b4fe", borderWidth: 1.5, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 4, tension: 0.35 },
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
                        displayColors: true,
                        callbacks: {
                            title: (contexts) => timestamp(points[contexts[0]?.dataIndex]?.date ?? null),
                            label: (context) => `${context.dataset.label}: ${formatNumber(Math.round(Number(context.parsed.y)))} ms`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: muted, maxTicksLimit: 6, font: { family: fontFamily, size: 11, weight: 600 } } },
                    y: { title: { display: true, text: "ms", color: muted, font: { family: fontFamily, size: 11, weight: 600 } }, grid: { color: border }, border: { display: false }, ticks: { color: muted, font: { family: fontFamily, size: 11, weight: 600 }, callback: (value) => formatNumber(Number(value)) } },
                },
            },
        });
    }

    $effect(() => {
        if (!responseTimeCanvas || points.length === 0) {
            responseTimeChart?.destroy();
            responseTimeChart = null;

            return;
        }

        void renderResponseTimeChart();
    });

    onMount(() => {
        const observer = new MutationObserver(() => void renderResponseTimeChart());
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });

        return () => {
            observer.disconnect();
            responseTimeChart?.destroy();
        };
    });

    async function loadMoreChecks(): Promise<void> {
        if (loadingChecks || checksMeta.next_offset === null) return;

        loadingChecks = true;
        checksError = "";

        try {
            const payload = await requestFirstPartyApi<MonitoringDetailData, MonitoringDetailMeta>(
                `/api/monitorings/${encodeURIComponent(monitoringId)}/detail-data?checks_offset=${checksMeta.next_offset}`,
            );
            checks = [...checks, ...payload.data.recent_checks];
            checksMeta = payload.meta?.recent_checks ?? { ...checksMeta, has_more: false, next_offset: null };
        } catch (error) {
            checksError = error instanceof FirstPartyApiError ? error.message : "Additional checks could not be loaded.";
        } finally {
            loadingChecks = false;
        }
    }

    async function changeResponseTimePeriod(event: Event): Promise<void> {
        const requestedDays = Number((event.currentTarget as HTMLSelectElement).value) as ResponseTimePeriod;
        if (loadingResponseTimes || requestedDays === responseTimeDays) return;

        loadingResponseTimes = true;
        responseTimesError = "";

        try {
            const payload = await requestFirstPartyApi<MonitoringDetailData, MonitoringDetailMeta>(
                `/api/monitorings/${encodeURIComponent(monitoringId)}/detail-data?response_time_days=${requestedDays}`,
            );
            responseTimes = payload.data.response_times;
            responseTimeDays = payload.meta?.response_times?.days ?? requestedDays;
            await tick();
            await renderResponseTimeChart();
        } catch (error) {
            responseTimesError = error instanceof FirstPartyApiError ? error.message : "Response-time data could not be loaded.";
        } finally {
            loadingResponseTimes = false;
        }
    }

    async function loadMoreIncidents(): Promise<void> {
        if (loadingIncidents || currentIncidentsMeta.next_offset === null) return;

        loadingIncidents = true;
        incidentsError = "";

        try {
            const payload = await requestFirstPartyApi<MonitoringDetailData, MonitoringDetailMeta>(
                `/api/monitorings/${encodeURIComponent(monitoringId)}/detail-data?incident_offset=${currentIncidentsMeta.next_offset}`,
            );
            incidents = [...incidents, ...payload.data.incidents];
            currentIncidentsMeta = payload.meta?.incidents ?? { ...currentIncidentsMeta, has_more: false, next_offset: null };
        } catch (error) {
            incidentsError = error instanceof FirstPartyApiError ? error.message : "Additional incidents could not be loaded.";
        } finally {
            loadingIncidents = false;
        }
    }
</script>

<section class="mt-7" aria-labelledby="uptime-calendar-heading">
    <h2 id="uptime-calendar-heading" class="text-2xl font-extrabold">Uptime Calendar</h2>
    {#if canLoadPreviousCalendarMonth}
        <div class="mt-3 flex justify-start">
            <Button
                variant="secondary"
                class="size-11 px-0"
                loading={loadingPreviousCalendarMonth}
                onclick={loadPreviousCalendarMonth}
                aria-label="Load previous month"
                title="Load previous month"
            >
                <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m12.5 15-5-5 5-5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </Button>
        </div>
    {/if}
    {#if calendarMonths.length > 0}
        <div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {#each calendarMonths as [month, value]}
                <article class="w-full rounded-[1.125rem] border border-wg-border bg-wg-surface p-6 shadow-sm">
                    <div class="flex items-baseline gap-2"><h3 class="text-lg font-extrabold">{formatMonthYear(new Date(`${month}-01T00:00:00`))}</h3><span class="text-xs font-bold text-wg-text-muted">({value.monthly_average_uptime === null ? "—" : formatPercentage(value.monthly_average_uptime)})</span></div>
                    <div class="mt-4 grid grid-cols-7 gap-1.5 text-center text-[0.625rem] text-wg-text-muted" aria-hidden="true"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
                    <div class="mt-2 grid grid-cols-7 gap-1.5" aria-label={`Availability for ${month}`}>
                        {#each calendarOffset(value.days[0]?.date ?? "") as _}
                            <span class="aspect-square"></span>
                        {/each}
                        {#each value.days as day}
                            <button
                                class={`group relative aspect-square rounded-[0.2rem] ${uptimeTone(day.uptime_percentage)} focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus`}
                                type="button"
                            >
                                <span class="sr-only">{calendarDayLabel(day.date)} · {uptimePercentage(day.uptime_percentage)} <span>Uptime</span></span>
                                <span
                                    class="pointer-events-none invisible absolute bottom-[calc(100%+0.5rem)] left-1/2 z-20 w-max max-w-48 -translate-x-1/2 rounded-md bg-wg-text px-2.5 py-2 text-center text-xs font-bold leading-5 text-wg-surface opacity-0 shadow-lg transition group-hover:visible group-hover:opacity-100 group-focus-visible:visible group-focus-visible:opacity-100"
                                    role="tooltip"
                                >
                                    <span class="block text-wg-surface/75">{calendarDayLabel(day.date)}</span>
                                    <span class="mt-0.5 block text-sm">{uptimePercentage(day.uptime_percentage)} <span>Uptime</span></span>
                                </span>
                            </button>
                        {/each}
                    </div>
                </article>
            {/each}
        </div>
    {:else}<p class="mt-3 text-sm leading-6 text-wg-text-muted">Daily availability will appear after a full monitoring day.</p>{/if}
    {#if uptimeCalendarError}<p class="mt-3 text-sm font-bold text-wg-danger" role="alert">{uptimeCalendarError}</p>{/if}
    <article class="mt-4 rounded-[0.8rem] border border-wg-border bg-wg-surface px-4 py-4 shadow-sm sm:px-6"><div class="flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs text-wg-text-muted"><span class="inline-flex items-center gap-2"><span class="size-3 rounded-sm bg-emerald-500"></span>≥ 97.5 %</span><span class="inline-flex items-center gap-2"><span class="size-3 rounded-sm bg-amber-400"></span>≥ 90 % and &lt; 97.5 %</span><span class="inline-flex items-center gap-2"><span class="size-3 rounded-sm bg-red-500"></span>&lt; 90 %</span><span class="inline-flex items-center gap-2"><span class="size-3 rounded-sm bg-wg-surface-muted"></span>N/A</span></div></article>
</section>

<section class="mt-7" aria-labelledby="response-time-heading">
    <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between"><h2 id="response-time-heading" class="shrink-0 text-2xl font-extrabold">Response Time</h2><Select width="compact" density="compact" value={String(responseTimeDays)} onchange={changeResponseTimePeriod} disabled={loadingResponseTimes} class="font-semibold text-wg-text-muted" aria-label="Response time period"><option value="1">Daily</option><option value="7">Weekly</option><option value="30">Monthly</option></Select></div>
    <article class="mt-3 rounded-[0.8rem] border border-wg-border bg-wg-surface p-4 shadow-sm sm:p-6">
        {#if points.length > 0}
            <div class="h-64 sm:h-96" role="img" aria-label="Response time line chart in milliseconds"><canvas bind:this={responseTimeCanvas}></canvas></div>
        {:else}<p class="text-sm leading-6 text-wg-text-muted">{loadingResponseTimes ? "Loading response-time data…" : "Response-time data will appear after the monitoring collects results."}</p>{/if}
    </article>
    {#if responseTimesError}<p class="mt-3 text-sm font-bold text-wg-danger" role="alert">{responseTimesError}</p>{/if}
    {#if points.length > 0}<dl class="mt-4 grid gap-4 text-center sm:grid-cols-3"><div class="rounded-[0.8rem] border border-wg-border bg-wg-surface p-5 shadow-sm"><dt class="text-sm text-wg-text-muted">Minimum</dt><dd class="mt-1 text-lg font-extrabold">{formatNumber(Math.round(responseTimes.aggregated.min ?? 0))} ms</dd></div><div class="rounded-[0.8rem] border border-wg-border bg-wg-surface p-5 shadow-sm"><dt class="text-sm text-wg-text-muted">Average</dt><dd class="mt-1 text-lg font-extrabold">{formatNumber(Math.round(responseTimes.aggregated.avg ?? 0))} ms</dd></div><div class="rounded-[0.8rem] border border-wg-border bg-wg-surface p-5 shadow-sm"><dt class="text-sm text-wg-text-muted">Maximum</dt><dd class="mt-1 text-lg font-extrabold">{formatNumber(Math.round(responseTimes.aggregated.max ?? 0))} ms</dd></div></dl>{/if}
</section>

<section class="mt-7" aria-labelledby="incidents-heading"><h2 id="incidents-heading" class="text-2xl font-extrabold">Incidents</h2>{#if incidents.length > 0}<ul class="mt-3 grid gap-3 p-0">{#each incidents as incident}<li class="list-none rounded-[0.8rem] border border-wg-border bg-wg-surface p-4 shadow-sm"><p class="font-bold">{incident.up_at ? "Resolved incident" : "Open incident"}</p><p class="mt-1 text-sm text-wg-text-muted">{timestamp(incident.down_at)} · {duration(incident)}</p></li>{/each}</ul>{:else}<p class="mt-3 text-sm text-wg-text-muted">No incidents recorded in this period.</p>{/if}{#if incidentsError}<p class="mt-3 text-sm font-bold text-wg-danger" role="alert">{incidentsError}</p>{/if}{#if currentIncidentsMeta.has_more}<div class="mt-5 flex justify-center"><Button variant="secondary" loading={loadingIncidents} onclick={loadMoreIncidents}>Load more</Button></div>{/if}</section>

<section class="mt-7" aria-labelledby="recent-checks-heading">
    <div><h2 id="recent-checks-heading" class="text-2xl font-extrabold">Recent Checks</h2><p class="mt-1 text-sm text-wg-text-muted">{formatNumber(checks.length)} loaded checks · Live and archived data</p></div>
    {#if checks.length > 0}
        <div class="relative mt-3 overflow-hidden rounded-[0.8rem] border border-wg-border bg-wg-surface shadow-sm">
            {#each checks as check, index (check.id)}
                <article class="relative z-10 grid grid-cols-[1.25rem_minmax(0,1fr)] gap-x-3 gap-y-3 px-4 py-4 transition-colors hover:bg-wg-surface-muted/50 sm:grid-cols-[1.5rem_minmax(11rem,0.9fr)_minmax(0,1.6fr)_auto] sm:items-center sm:gap-4 sm:px-5 sm:py-5">
                    {#if index < checks.length - 1}<span class={`pointer-events-none absolute top-[1.625rem] bottom-[-1.625rem] left-[1.625rem] z-[-1] w-px sm:left-8 ${timelineConnectorTone(check.status)}`} aria-hidden="true"></span>{/if}
                    <div class="row-span-3 flex justify-center pt-0.5 sm:row-span-1 sm:pt-0"><span class={`grid size-5 shrink-0 place-items-center rounded-full border ${timelineDotTone(check.status)}`} aria-label={check.status}>{#if check.status === "unknown"}<span class="text-xs font-extrabold" aria-hidden="true">!</span>{:else}<svg class="size-3" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d={check.status === "up" ? "M6.35 11.15 2.9 7.7l1.05-1.05 2.4 2.4 5.7-5.7L13.1 4.4z" : "m4.05 3 3.95 3.95L11.95 3 13 4.05 9.05 8 13 11.95 11.95 13 8 9.05 4.05 13 3 11.95 6.95 8 3 4.05z"} /></svg>{/if}</span></div>
                    <div class="min-w-0"><p class="text-sm font-extrabold">{timestamp(check.checked_at)}</p><p class="mt-1 text-xs text-wg-text-muted">{check.source === "live" ? "Live" : "Archived"}</p></div>
                    <dl class="col-start-2 mt-0 grid min-w-0 grid-cols-2 gap-x-4 gap-y-3 text-sm sm:col-start-auto sm:grid-cols-3 sm:gap-x-5"><div><dt class="text-[0.625rem] font-extrabold tracking-[0.1em] text-wg-text-muted uppercase">Status code</dt><dd class="mt-1 font-extrabold">{check.http_status_code ?? "—"}</dd></div><div><dt class="text-[0.625rem] font-extrabold tracking-[0.1em] text-wg-text-muted uppercase">Response time</dt><dd class="mt-1 font-extrabold text-sky-700 dark:text-sky-300">{check.response_time === null ? "—" : `${formatNumber(Math.round(check.response_time))} ms`}</dd></div><div><dt class="text-[0.625rem] font-extrabold tracking-[0.1em] text-wg-text-muted uppercase">Raw status</dt><dd class="mt-1 font-extrabold uppercase">{check.status}</dd></div></dl>
                    <span class={`col-start-2 mt-0 inline-flex w-fit max-w-full items-center justify-self-start whitespace-nowrap rounded-full px-2.5 py-0.5 text-[0.5625rem] leading-none font-extrabold tracking-[0.08em] uppercase sm:col-start-auto sm:justify-self-end ${statusTone(check.status)}`}>{check.status === "up" ? "Successful" : check.status}</span>
                </article>
            {/each}
        </div>
        {#if checksError}<p class="mt-3 text-sm font-bold text-wg-danger" role="alert">{checksError}</p>{/if}
        {#if checksMeta.has_more}<div class="mt-5 flex justify-center"><Button variant="secondary" loading={loadingChecks} onclick={loadMoreChecks}>Load more</Button></div>{/if}
    {:else}<p class="mt-3 text-sm text-wg-text-muted">No checks have been recorded yet.</p>{/if}
</section>
