<script lang="ts">
    import { enhance } from "$app/forms";
    import { formatDateTime } from "$lib/i18n/format";
    import { translate, type TranslationMessages } from "$lib/i18n/localize";
    import Button from "$lib/components/Button.svelte";
    import Input from "$lib/components/Input.svelte";
    import AppearanceSelector from "$lib/components/AppearanceSelector.svelte";
    import LocaleSelector from "$lib/components/LocaleSelector.svelte";
    import type { PublicStatusPayload } from "$lib/api/public-status";

    interface FormState {
        email?: string;
        error?: string;
        message?: string;
    }

    interface PublicStatusPageData extends PublicStatusPayload {
        locale: string;
        messages: TranslationMessages;
        subscriptionNotice: string | null;
    }

    interface Props {
        data: PublicStatusPageData;
        form?: FormState;
    }

    let { data, form }: Props = $props();

    function text(value: string): string {
        return translate(value, data.locale, data.messages);
    }

    const statusLabel = $derived(text(data.status === "up" ? "All systems operational" : data.status === "down" ? "Service disruption" : "Status unavailable"));
    const statusClasses = $derived(data.status === "up" ? "border-emerald-200 bg-emerald-50 text-emerald-900" : data.status === "down" ? "border-red-200 bg-red-50 text-red-900" : "border-amber-200 bg-amber-50 text-amber-900");
    const statusDot = $derived(data.status === "up" ? "bg-emerald-500" : data.status === "down" ? "bg-red-500" : "bg-amber-500");
    const calendarDays = $derived(Object.values(data.uptime_calendar ?? {}).flatMap((month) => month.days));
    function dateTime(value: string | null): string {
        return formatDateTime(value, text("Not recorded"));
    }

    function updateStatusLabel(status: string): string {
        return text(status.charAt(0).toUpperCase() + status.slice(1));
    }

    function percentage(value: number | null): string {
        return value === null ? text("No data") : `${value.toFixed(2)}%`;
    }

    function stateClasses(status: "up" | "down" | "unknown"): string {
        return status === "up" ? "bg-emerald-100 text-emerald-800" : status === "down" ? "bg-red-100 text-red-800" : "bg-amber-100 text-amber-800";
    }

    function dayClasses(value: number | null): string {
        if (value === null) return "bg-slate-200";
        if (value >= 99.9) return "bg-emerald-500";
        if (value >= 95) return "bg-emerald-300";
        if (value > 0) return "bg-amber-400";
        return "bg-red-500";
    }

    function calendarDayLabel(value: string): string {
        return formatDateTime(value, "Not recorded", {
            month: "short", day: "numeric", year: "numeric",
        });
    }

</script>

<svelte:head><title>{data.name} | WebGuard Status</title><meta name="description" content={data.description ?? `Current status for ${data.name}.`} /></svelte:head>

<main class="min-h-screen bg-wg-canvas px-4 py-8 sm:px-6 sm:py-14">
    <div class="mx-auto w-full max-w-5xl">
        <header class="mx-auto max-w-3xl text-center"><div class="flex items-center justify-between gap-4"><a class="flex min-w-0 items-center gap-3 text-wg-text no-underline" href="/" aria-label="WebGuard"><img class="size-10 shrink-0 rounded-xl bg-white object-contain p-0.5" src="/brand/webguard-logo.png" alt="" /><span class="font-extrabold tracking-tight">WebGuard</span></a><div class="flex gap-2" aria-label="Page preferences"><AppearanceSelector endpoint={null} menuAlign="right" menuPlacement="below" variant="surface" /><LocaleSelector initialLocale={data.locale} endpoint={null} menuAlign="right" menuPlacement="below" variant="surface" /></div></div><h1 class="mt-5 text-[clamp(2rem,6vw,3.5rem)] leading-[1.05] font-bold tracking-tight">{data.name}</h1>{#if data.description}<p class="mx-auto mt-4 max-w-2xl leading-7 text-wg-text-muted">{data.description}</p>{/if}{#if data.kind === "monitoring" && data.monitoring?.target}<a class="mt-4 inline-block break-all text-sm font-bold text-wg-accent" href={data.monitoring.target} target="_blank" rel="noreferrer">{data.monitoring.target}</a>{/if}</header>

        <section class={`mt-9 rounded-2xl border px-5 py-5 shadow-sm sm:px-6 ${statusClasses}`} aria-label="Overall service status"><div class="flex items-center gap-4"><span class={`size-3.5 shrink-0 rounded-full ${statusDot}`} aria-hidden="true"></span><div><p class="text-lg font-bold sm:text-xl">{statusLabel}</p><p class="mt-1 text-sm opacity-80">Overall service status: {data.status.toUpperCase()}</p></div></div></section>

        {#if data.kind === "status_page" && data.announcement}<section class="mt-6 rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sky-950 shadow-sm sm:p-6 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100" aria-labelledby="status-page-announcement"><p class="text-xs font-extrabold tracking-[0.12em] uppercase text-sky-700 dark:text-sky-300">Announcement</p><h2 id="status-page-announcement" class="mt-2 text-xl font-bold">{data.announcement.title}</h2><p class="mt-3 whitespace-pre-line leading-6">{data.announcement.message}</p>{#if data.announcement.published_at}<p class="mt-4 text-xs text-sky-800/80 dark:text-sky-200/80">Published {dateTime(data.announcement.published_at)}</p>{/if}</section>{/if}

        {#if data.kind === "status_page"}<section class="mt-6 grid gap-4 md:grid-cols-2">{#each data.components ?? [] as component (component.id)}<article class="rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-sm sm:p-6"><div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-xl font-bold">{component.name}</h2>{#if component.description}<p class="mt-2 text-sm leading-6 text-wg-text-muted">{component.description}</p>{/if}</div><div class="flex gap-2">{#if component.has_maintenance}<span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-bold text-sky-800">Maintenance</span>{/if}<span class={`rounded-full px-3 py-1 text-xs font-bold ${stateClasses(component.status)}`}>{component.status.toUpperCase()}</span></div></div><div class="mt-5 divide-y divide-wg-border">{#each component.monitorings as monitoring (monitoring.id)}<div class="flex flex-wrap items-center justify-between gap-3 py-3"><div><p class="font-bold">{monitoring.name}</p><p class="mt-1 text-sm text-wg-text-muted">{monitoring.type}{#if monitoring.last_checked_at} · Last checked {dateTime(monitoring.last_checked_at)}{/if}</p></div><div class="flex gap-2">{#if monitoring.is_under_maintenance}<span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-bold text-sky-800">Maintenance</span>{/if}<span class={`rounded-full px-3 py-1 text-xs font-bold ${stateClasses(monitoring.status)}`}>{monitoring.status.toUpperCase()}</span></div></div>{/each}</div></article>{/each}</section>{:else if data.monitoring}<section class="mt-6 rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-sm sm:p-6"><div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-xl font-bold">{data.name}</h2><p class="mt-2 text-sm text-wg-text-muted">{data.monitoring.type}{#if data.monitoring.last_checked_at} · Last checked {dateTime(data.monitoring.last_checked_at)}{/if}</p></div><div class="flex gap-2">{#if data.monitoring.is_under_maintenance}<span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-bold text-sky-800">Maintenance</span>{/if}<span class={`rounded-full px-3 py-1 text-xs font-bold ${stateClasses(data.status)}`}>{data.status.toUpperCase()}</span></div></div>{#if data.monitoring.maintenance_window}<div class="mt-5 rounded-xl bg-sky-50 p-4 text-sm text-sky-900"><p class="font-bold">{data.monitoring.maintenance_window.active ? "Maintenance in progress" : "Upcoming maintenance"}</p><p class="mt-1">{dateTime(data.monitoring.maintenance_window.starts_at)} · {data.monitoring.maintenance_window.ends_at ? dateTime(data.monitoring.maintenance_window.ends_at) : "Open-ended"}</p></div>{/if}{#if data.monitoring.http_status_code}<p class="mt-5 text-sm text-wg-text-muted">HTTP {data.monitoring.http_status_code}</p>{/if}</section>{/if}

        {#if data.kind === "monitoring" && data.monitoring}<section class="mt-8"><h2 class="text-xl font-bold">Uptime</h2><div class="mt-4 grid gap-4 sm:grid-cols-3">{#each [7, 30, 90] as days}<article class="rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-sm"><p class="font-bold">Last {days} days</p><p class="mt-3 text-3xl font-bold text-wg-accent">{percentage(data.monitoring.uptime[String(days)]?.uptime.percentage ?? null)}</p><p class="mt-2 text-sm text-wg-text-muted">{data.monitoring.uptime[String(days)]?.downtime.incidents_count ?? 0} incidents</p></article>{/each}</div></section>{/if}

        {#if calendarDays.length > 0}<section class="mt-8 rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-sm sm:p-6"><h2 class="text-xl font-bold">30-day uptime</h2><p class="mt-2 text-sm text-wg-text-muted">Each square represents a day of aggregated availability.</p><div class="mt-5 grid grid-cols-10 gap-2 sm:grid-cols-[repeat(15,minmax(0,1fr))]">{#each calendarDays as day (day.date)}<button class={`group relative aspect-square rounded-md ${dayClasses(day.uptime_percentage)} focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus`} type="button" aria-label={`${calendarDayLabel(day.date)}: ${percentage(day.uptime_percentage)}`}><span class="pointer-events-none invisible absolute bottom-[calc(100%+0.5rem)] left-1/2 z-20 w-max max-w-48 -translate-x-1/2 rounded-md bg-wg-text px-2.5 py-2 text-center text-xs font-bold leading-5 text-wg-surface opacity-0 shadow-lg transition group-hover:visible group-hover:opacity-100 group-focus-visible:visible group-focus-visible:opacity-100" role="tooltip"><span class="block text-wg-surface/75">{calendarDayLabel(day.date)}</span><span class="mt-0.5 block text-sm">{percentage(day.uptime_percentage)} <span>Uptime</span></span></span></button>{/each}</div></section>{/if}

        <section class="mt-8 rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-sm sm:p-6"><div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between"><div><h2 class="text-xl font-bold">Subscribe to updates</h2><p class="mt-2 text-sm leading-6 text-wg-text-muted">Receive email notifications when this status changes.</p></div><form method="POST" class="grid w-full gap-3 md:max-w-md" use:enhance novalidate><label class="sr-only" for="subscriber-email">Email address</label><div class="flex flex-col gap-3 sm:flex-row"><Input id="subscriber-email" class="min-w-0 flex-1" name="email" type="email" autocomplete="email" required placeholder="you@example.com" aria-describedby={form?.error ? "subscriber-email-error" : undefined} value={form?.email ?? ""} /><Button type="submit">Subscribe</Button></div>{#if data.subscriptionNotice}<p class="text-sm font-bold text-emerald-700" role="status">{data.subscriptionNotice}</p>{/if}{#if form?.message}<p class="text-sm font-bold text-emerald-700" role="status">{form.message}</p>{/if}{#if form?.error}<p id="subscriber-email-error" class="text-sm font-bold text-wg-danger" role="alert">{form.error}</p>{/if}</form></div></section>

        <section class="mt-8 rounded-2xl border border-wg-border bg-wg-surface p-5 shadow-sm sm:p-6"><h2 class="text-xl font-bold">Recent incidents</h2>{#if data.incidents.length === 0}<p class="mt-4 text-sm leading-6 text-wg-text-muted">No recent incidents have been recorded.</p>{:else}<div class="mt-4 divide-y divide-wg-border">{#each data.incidents as incident, index (`${incident.monitoring_name}-${index}`)}<article class="py-5 first:pt-0 last:pb-0"><div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-bold">{incident.monitoring_name}</h3><span class={`rounded-full px-3 py-1 text-xs font-bold ${incident.up_at ? "bg-emerald-100 text-emerald-800" : "bg-red-100 text-red-800"}`}>{incident.up_at ? "Resolved" : "Ongoing"}</span></div><p class="mt-2 text-sm text-wg-text-muted">Started {dateTime(incident.down_at)}{#if incident.up_at} · Resolved {dateTime(incident.up_at)}{/if}</p>{#if incident.updates.length > 0}<div class="mt-4 grid gap-3">{#each incident.updates as update, updateIndex (`${update.published_at}-${updateIndex}`)}<div class="border-l-2 border-wg-accent pl-3"><p class="text-sm font-bold">{updateStatusLabel(update.status)}</p><p class="mt-1 text-sm leading-6 text-wg-text-muted">{update.message}</p><p class="mt-1 text-xs text-wg-text-muted">{dateTime(update.published_at)}</p></div>{/each}</div>{/if}</article>{/each}</div>{/if}</section>
    </div>
</main>
