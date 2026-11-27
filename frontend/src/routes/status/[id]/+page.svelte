<script lang="ts">
    import * as Alert from "#lib/components/ui/alert/index.ts";
    import * as Card from "#lib/components/ui/card/index.ts";
    import * as Empty from "#lib/components/ui/empty/index.ts";
    import * as Tooltip from "#lib/components/ui/tooltip/index.ts";
    import { Badge } from "#lib/components/ui/badge/index.ts";
    import { Label } from "#lib/components/ui/label/index.ts";
    import CircleHelp from "@lucide/svelte/icons/circle-help";
    import CircleCheck from "@lucide/svelte/icons/circle-check";
    import TriangleAlert from "@lucide/svelte/icons/triangle-alert";
    import { enhance } from "$app/forms";
    import { formatDateTime, formatNumber, formatPercentage } from "#lib/i18n/format.ts";
    import { translate, type TranslationMessages } from "#lib/i18n/localize.ts";
    import Button from "#lib/components/Button.svelte";
    import Input from "#lib/components/Input.svelte";
    import AppearanceSelector from "#lib/components/AppearanceSelector.svelte";
    import LocaleSelector from "#lib/components/LocaleSelector.svelte";
    import type { PublicStatusPayload } from "#lib/api/public-status.ts";

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

    const statusLabel = $derived(text(data.status === "up" ? "All systems operational" : data.status === "down" ? "Major service outage" : "Partial service disruption"));
    const statusClasses = $derived(data.status === "up" ? "border-wg-status-up bg-wg-status-up text-wg-status-up-foreground" : data.status === "down" ? "border-wg-status-down bg-wg-status-down text-wg-status-down-foreground" : "border-wg-status-degraded bg-wg-status-degraded text-wg-status-degraded-foreground");
    const maintenanceComponents = $derived((data.components ?? []).filter((component) => component.has_maintenance));
    const activeIncidents = $derived(data.incidents.filter((incident) => incident.up_at === null));
    const resolvedIncidents = $derived(data.incidents.filter((incident) => incident.up_at !== null));
    const stateLabel = (status: string) => text(status === "up" ? "Operational" : status === "down" ? "Down" : "Unknown");
    const uptime90 = $derived(data.monitoring?.uptime["90"]?.uptime.percentage ?? null);
    const calendarDays = $derived(Object.values(data.uptime_calendar ?? {}).flatMap((month) => month.days));
    function dateTime(value: string | null): string {
        return formatDateTime(value, text("Not recorded"));
    }

    function updateStatusLabel(status: string): string {
        return text(status.charAt(0).toUpperCase() + status.slice(1));
    }

    function percentage(value: number | null): string {
        return value === null ? text("No data") : formatPercentage(value);
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

<svelte:head><title>{data.name} | WebGuard Status</title><meta name="description" content={data.description ?? `Current status for ${data.name}.`} /><meta name="robots" content={data.kind === "status_page" ? "index, follow" : "noindex, nofollow"} /></svelte:head>

<main class="min-h-screen px-4 py-8 sm:px-6 sm:py-14">
    <div class="mx-auto w-full max-w-5xl">
        <header class="mx-auto max-w-3xl text-center"><div class="flex items-center justify-between gap-4"><a class="flex min-w-0 items-center gap-3 text-wg-text no-underline" href="/" aria-label="WebGuard"><img class="size-10 shrink-0 rounded-xl bg-white object-contain p-0.5" src="/brand/webguard-logo.png" alt="" /><span class="font-extrabold tracking-tight">WebGuard</span></a><div class="flex gap-2" aria-label="Page preferences"><AppearanceSelector endpoint={null} menuAlign="right" menuPlacement="below" variant="surface" /><LocaleSelector initialLocale={data.locale} endpoint={null} menuAlign="right" menuPlacement="below" variant="surface" /></div></div><h1 class="mt-5 text-[clamp(2rem,6vw,3.5rem)] leading-[1.05] font-bold tracking-tight">{data.name}</h1>{#if data.description}<p class="mx-auto mt-4 max-w-2xl leading-7 text-wg-text-muted">{data.description}</p>{/if}{#if data.kind === "monitoring" && data.monitoring?.target}<a class="mt-4 inline-block break-all text-sm font-bold text-wg-accent" href={data.monitoring.target} target="_blank" rel="noreferrer">{data.monitoring.target}</a>{/if}</header>

        <Alert.Root role="region" class={`mt-8 rounded-2xl border px-5 py-5 shadow-none sm:px-7 sm:py-6 ${statusClasses}`} aria-label="Overall service status">
            <div class="flex items-start gap-4">
                {#if data.status === "up"}<CircleCheck class="mt-0.5 size-6 shrink-0" aria-hidden="true" />{:else if data.status === "down"}<TriangleAlert class="mt-0.5 size-6 shrink-0" aria-hidden="true" />{:else}<CircleHelp class="mt-0.5 size-6 shrink-0" aria-hidden="true" />{/if}
                <p class="text-lg font-bold sm:text-xl">{statusLabel}</p>
            </div>
        </Alert.Root>

        {#if activeIncidents.length > 0}<section class="mt-6" aria-labelledby="active-incidents-heading"><Card.Root class="border-wg-status-down p-5 sm:p-6"><h2 id="active-incidents-heading" class="text-xl font-bold">{text("Active incidents")}</h2><ul class="mt-3 divide-y divide-wg-border">{#each activeIncidents as incident, index (`active-${incident.monitoring_name}-${index}`)}<li class="py-3 first:pt-0 last:pb-0"><div class="flex flex-wrap items-center justify-between gap-2"><span class="font-semibold">{incident.monitoring_name}</span><span class="text-sm text-wg-text-muted">{text("Ongoing")} · {text("Started")} {dateTime(incident.down_at)}</span></div>{@render Updates(incident.updates)}</li>{/each}</ul></Card.Root></section>{/if}

        {#if data.kind === "status_page" && maintenanceComponents.length > 0}<section class="mt-6" aria-labelledby="maintenance-heading"><Card.Root class="border-wg-border p-5 sm:p-6"><h2 id="maintenance-heading" class="text-xl font-bold">{text("Scheduled maintenance")}</h2><ul class="mt-4 grid gap-2">{#each maintenanceComponents as component (component.id)}<li class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-wg-surface-muted px-4 py-3"><span class="font-semibold">{component.name}</span><Badge class="bg-wg-status-maintenance text-wg-status-maintenance-foreground">{text("Maintenance")}</Badge></li>{/each}</ul></Card.Root></section>{/if}

        {#if data.kind === "status_page" && data.announcement}<Alert.Root role="region" class="mt-6 rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sky-950 shadow-sm sm:p-6 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100" aria-labelledby="status-page-announcement"><p class="text-xs font-extrabold tracking-[0.12em] uppercase text-sky-700 dark:text-sky-300">Announcement</p><h2 id="status-page-announcement" class="mt-2 text-xl font-bold">{data.announcement.title}</h2><p class="mt-3 whitespace-pre-line leading-6">{data.announcement.message}</p>{#if data.announcement.published_at}<p class="mt-4 text-xs text-sky-800/80 dark:text-sky-200/80">Published {dateTime(data.announcement.published_at)}</p>{/if}</Alert.Root>{/if}

        {#if data.kind === "status_page"}<section class="mt-8 grid gap-4" aria-label="Service components">{#each data.components ?? [] as component (component.id)}<Card.Root as="section" class="p-5 sm:p-6"><div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h2 class="text-xl font-bold">{component.name}</h2>{#if component.description}<p class="mt-2 text-sm leading-6 text-wg-text-muted">{component.description}</p>{/if}</div><Badge class={`rounded-full px-3 py-1 text-xs font-semibold ${stateClasses(component.status)}`}>{stateLabel(component.status)}</Badge></div><div class="mt-4 divide-y divide-wg-border">{#each component.monitorings as monitoring (monitoring.id)}<div class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><p class="font-semibold">{monitoring.name}</p><p class="mt-1 text-sm text-wg-text-muted">{text(monitoring.type)}{#if monitoring.last_checked_at} · {text("Last checked")} {dateTime(monitoring.last_checked_at)}{/if}</p></div><div class="flex shrink-0 flex-wrap items-center gap-2">{#if monitoring.is_under_maintenance}<Badge class="bg-wg-status-maintenance text-wg-status-maintenance-foreground">{text("Maintenance")}</Badge>{/if}<Badge class={`px-2.5 py-1 text-xs font-semibold ${stateClasses(monitoring.status)}`}>{stateLabel(monitoring.status)}</Badge></div></div>{/each}</div></Card.Root>{/each}</section>{:else if data.monitoring}<Card.Root as="section" class="mt-6 p-5 sm:p-6"><div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-xl font-bold">{data.name}</h2><p class="mt-2 text-sm text-wg-text-muted">{text(data.monitoring.type)}{#if data.monitoring.last_checked_at} · {text("Last checked")} {dateTime(data.monitoring.last_checked_at)}{/if}</p></div><div class="flex gap-2">{#if data.monitoring.is_under_maintenance}<Badge class="bg-wg-status-maintenance text-wg-status-maintenance-foreground">{text("Maintenance")}</Badge>{/if}<Badge class={`px-2.5 py-1 text-xs font-semibold ${stateClasses(data.status)}`}>{stateLabel(data.status)}</Badge></div></div>{#if data.monitoring.maintenance_window}<Alert.Root variant="inline" role="note" class="mt-5 rounded-xl bg-wg-status-maintenance p-4 text-sm text-wg-status-maintenance-foreground"><Alert.Title class="font-bold">{text(data.monitoring.maintenance_window.active ? "Maintenance in progress" : "Upcoming maintenance")}</Alert.Title><Alert.Description class="mt-1 text-inherit [text-wrap:wrap]">{dateTime(data.monitoring.maintenance_window.starts_at)} · {data.monitoring.maintenance_window.ends_at ? dateTime(data.monitoring.maintenance_window.ends_at) : text("Open-ended")}</Alert.Description></Alert.Root>{/if}{#if data.monitoring.http_status_code}<p class="mt-5 text-sm text-wg-text-muted">HTTP {data.monitoring.http_status_code}</p>{/if}</Card.Root>{/if}

        {#if data.kind === "monitoring" && data.monitoring}<section class="mt-8"><h2 class="text-xl font-bold">Uptime</h2><div class="mt-4 grid gap-4 sm:grid-cols-3">{#each [7, 30, 90] as days}<Card.Root class="p-5"><p class="font-bold">Last {formatNumber(days)} days</p><p class="mt-3 text-3xl font-bold text-wg-accent">{percentage(data.monitoring.uptime[String(days)]?.uptime.percentage ?? null)}</p>{#if days === 90 && uptime90 !== null}<div class="mt-4"><svg class="h-2 w-full overflow-hidden rounded-full" role="progressbar" aria-label="Last 90 days" aria-valuemin="0" aria-valuemax="100" aria-valuenow={Math.min(100, Math.max(0, uptime90))}><rect width="100%" height="100%" class="fill-wg-surface-muted" /><rect width={`${Math.min(100, Math.max(0, uptime90))}%`} height="100%" class="fill-wg-status-up-foreground" /></svg></div>{/if}<p class="mt-2 text-sm text-wg-text-muted">{formatNumber(data.monitoring.uptime[String(days)]?.downtime.incidents_count ?? 0)} incidents</p></Card.Root>{/each}</div></section>{/if}

        {#if calendarDays.length > 0}<Card.Root as="section" class="mt-8 p-5 sm:p-6">
            <h2 class="text-xl font-bold">30-day uptime</h2>
            <p class="mt-2 text-sm text-wg-text-muted">Each square represents a day of aggregated availability.</p>
            <Tooltip.Provider>
                <div class="mt-5 grid grid-cols-10 gap-2 sm:grid-cols-[repeat(15,minmax(0,1fr))]">
                    {#each calendarDays as day (day.date)}
                        <Tooltip.Root>
                            <Tooltip.Trigger type="button" class={`aspect-square rounded-md ${dayClasses(day.uptime_percentage)} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus`}
                                aria-label={`${calendarDayLabel(day.date)}: ${percentage(day.uptime_percentage)}`} />
                            <Tooltip.Content sideOffset={8} role="tooltip" portalProps={{ to: "#localized-app" }}>
                                <div class="text-center"><span class="block opacity-75">{calendarDayLabel(day.date)}</span><span class="mt-0.5 block text-sm font-bold">{percentage(day.uptime_percentage)} <span>Uptime</span></span></div>
                            </Tooltip.Content>
                        </Tooltip.Root>
                    {/each}
                </div>
            </Tooltip.Provider>
        </Card.Root>{/if}

        <Card.Root as="section" class="mt-8 p-5 sm:p-6"><div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between"><div><h2 class="text-xl font-bold">Subscribe to updates</h2><p class="mt-2 text-sm leading-6 text-wg-text-muted">Receive email notifications when this status changes.</p></div><form method="POST" class="grid w-full gap-3 md:max-w-md" use:enhance novalidate><Label class="sr-only" for="subscriber-email">Email address</Label><div class="flex flex-col gap-3 sm:flex-row"><Input id="subscriber-email" class="min-w-0 flex-1" name="email" type="email" autocomplete="email" required placeholder="you@example.com" aria-describedby={form?.error ? "subscriber-email-error" : undefined} value={form?.email ?? ""} /><Button type="submit">Subscribe</Button></div>{#if data.subscriptionNotice}<Alert.Root variant="inline" class="text-sm font-bold text-emerald-700" role="status">{data.subscriptionNotice}</Alert.Root>{/if}{#if form?.message}<Alert.Root variant="inline" class="text-sm font-bold text-emerald-700" role="status">{form.message}</Alert.Root>{/if}{#if form?.error}<Alert.Root id="subscriber-email-error" variant="destructive"><Alert.Description>{form.error}</Alert.Description></Alert.Root>{/if}</form></div></Card.Root>

        <Card.Root as="section" class="mt-8 p-5 sm:p-6"><h2 class="text-xl font-bold">Recent incidents</h2>{#if resolvedIncidents.length === 0}<Empty.Root class="mt-4 items-start border-0 p-0 text-left"><Empty.Description>No resolved incidents have been recorded.</Empty.Description></Empty.Root>{:else}<div class="mt-4 divide-y divide-wg-border">{#each resolvedIncidents as incident, index (`${incident.monitoring_name}-${index}`)}<article class="py-5 first:pt-0 last:pb-0"><div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-bold">{incident.monitoring_name}</h3><Badge class="bg-wg-status-up text-wg-status-up-foreground">Resolved</Badge></div><p class="mt-2 text-sm text-wg-text-muted">Started {dateTime(incident.down_at)} · Resolved {dateTime(incident.up_at)}</p>{@render Updates(incident.updates)}</article>{/each}</div>{/if}</Card.Root>
    </div>
</main>

{#snippet Updates(updates: typeof data.incidents[number]["updates"])}{#if updates.length > 0}<div class="mt-3 grid gap-3">{#each updates as update, updateIndex (`${update.published_at}-${updateIndex}`)}<div class="rounded-lg bg-wg-surface-muted p-3"><p class="flex items-center gap-2 text-sm font-semibold"><span class="size-2 shrink-0 rounded-full bg-wg-accent" aria-hidden="true"></span>{updateStatusLabel(update.status)}</p><p class="mt-1 text-sm leading-6 text-wg-text-muted">{update.message}</p><p class="mt-1 text-xs text-wg-text-muted">{dateTime(update.published_at)}</p></div>{/each}</div>{/if}{/snippet}
