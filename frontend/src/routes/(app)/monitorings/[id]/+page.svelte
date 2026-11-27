<script lang="ts">
    import { Separator } from "#lib/components/ui/separator/index.ts";
    import { EmptyDescription } from "#lib/components/ui/empty/index.ts";
    import * as Card from "#lib/components/ui/card/index.ts";
    import * as DropdownMenu from "#lib/components/ui/dropdown-menu/index.ts";
    import { Button, buttonVariants } from "#lib/components/ui/button/index.ts";
    import { Badge } from "#lib/components/ui/badge/index.ts";
    import { Alert } from "#lib/components/ui/alert/index.ts";
    import { goto, invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import { appRoutes } from "#lib/routes.ts";
    import { formatDateTime, formatNumber, formatPercentage } from "#lib/i18n/format.ts";
    import { translate, type TranslationMessages } from "#lib/i18n/localize.ts";
    import Dialog from "#lib/components/Dialog.svelte";
    import MonitoringAnalytics from "#lib/components/MonitoringAnalytics.svelte";
    import MonitoringForm from "#lib/components/MonitoringForm.svelte";
    import ServerHealthOverview from "#lib/components/ServerHealthOverview.svelte";
    import MonitoringTypeDiagnostics from "#lib/components/MonitoringTypeDiagnostics.svelte";
    import MonitoringLocationList from "#lib/components/MonitoringLocationList.svelte";
    import StatusBadge from "#lib/components/StatusBadge.svelte";
    import NavIcon from "#lib/components/NavIcon.svelte";
    import { notificationChannelLabel } from "#lib/notification-channels.ts";
    import type { MonitoringDetailData, MonitoringFormOptions, MonitoringMutationResult, MonitoringSummary } from "#lib/api/monitoring.ts";

    interface Props {
        data: {
            locale: string;
            messages: TranslationMessages;
            monitoring: MonitoringSummary;
            detail: MonitoringDetailData;
            incidentsMeta: { limit: number; offset: number; has_more: boolean; next_offset: number | null };
            recentChecksMeta: { limit: number; has_more: boolean; next_offset: number | null };
            uptimeCalendarMeta: { oldest_available_month: string | null };
        };
    }
    let { data }: Props = $props();
    let deleting = $state(false);
    let resettingResults = $state(false);
    let editForm = $state<MonitoringFormOptions | null>(null);
    let editLoading = $state(false);
    let editOpen = $state(false);
    let error = $state("");

    function text(value: string): string {
        return translate(value, data.locale, data.messages);
    }

    const linkedTarget = $derived(targetUrl(data.monitoring.target));
    const availabilityPeriods = $derived(data.detail.availability_periods);
    const checkLocations = $derived(data.monitoring.check_locations ?? data.detail.summary.check_regions.map((code) => ({ code, name: code })));

    function statusTone(): "healthy" | "degraded" | "danger" | "neutral" | "paused" {
        if (data.monitoring.lifecycle_status === "paused") return "paused";
        if (data.detail.current_check.status === "up") return "healthy";
        if (data.detail.current_check.status === "down") return "danger";
        if (data.detail.current_check.status === "unknown") return "degraded";
        return "neutral";
    }

    function statusLabel(): string {
        if (data.monitoring.lifecycle_status === "paused") return "Paused";
        return data.detail.current_check.status === "up" ? "Up" : data.detail.current_check.status === "down" ? "Down" : "Unknown";
    }

    function timestamp(value: string | null): string {
        return formatDateTime(value, "—", {
            month: "2-digit", day: "2-digit", year: "numeric",
            hour: "2-digit", minute: "2-digit", second: "2-digit",
        });
    }

    function intervalLabel(seconds: number | null): string {
        if (seconds === null || seconds <= 0) return "Waiting for the first result";
        return seconds % 60 === 0 ? `Checked every ${seconds / 60} minutes` : `Checked every ${seconds} seconds`;
    }

    function lastDayAvailability(): string | null {
        const uptime = data.detail.heatmap.reduce((total, point) => total + point.uptime, 0);
        const checks = uptime + data.detail.heatmap.reduce((total, point) => total + point.downtime, 0);

        return checks === 0 ? null : formatPercentage((uptime / checks) * 100, 1);
    }

    function targetUrl(target: string): string | null {
        try {
            const url = new URL(target);
            return ["http:", "https:"].includes(url.protocol) ? url.toString() : null;
        } catch {
            return null;
        }
    }

    function availability(days: "7" | "30" | "90"): string {
        const percentage = availabilityPeriods[days]?.uptime.percentage;
        return percentage === null || percentage === undefined ? "—" : formatPercentage(percentage);
    }

    function periodDescription(days: "7" | "30" | "90"): string {
        const period = availabilityPeriods[days];
        if (!period?.has_data) return "No availability data yet";
        const incidents = period.downtime.incidents_count;
        return `${formatNumber(incidents)} ${incidents === 1 ? "incident" : "incidents"}, ${formatPercentage(period.downtime.percentage ?? 0)} downtime`;
    }

    async function remove(): Promise<void> {
        if (deleting || !window.confirm(`Delete ${data.monitoring.name}? This cannot be undone.`)) return;

        deleting = true;
        error = "";

        try {
            await requestFirstPartyApi(`/api/monitorings/${data.monitoring.id}`, { method: "DELETE" });
            await goto(appRoutes.monitorings);
        } catch (exception) {
            error = exception instanceof FirstPartyApiError ? exception.message : "The monitoring could not be deleted.";
        } finally {
            deleting = false;
        }
    }

    async function resetResults(): Promise<void> {
        const confirmation = translate("Reset all results for :name? This also removes its check history and incidents.", data.locale, data.messages)
            .replace(":name", data.monitoring.name);
        if (resettingResults || !window.confirm(confirmation)) return;

        resettingResults = true;
        error = "";

        try {
            await requestFirstPartyApi(`/api/monitorings/${data.monitoring.id}/results`, { method: "DELETE" });
            await invalidateAll();
        } catch (exception) {
            error = exception instanceof FirstPartyApiError ? exception.message : translate("The monitoring results could not be reset.", data.locale, data.messages);
        } finally {
            resettingResults = false;
        }
    }

    async function openEdit(): Promise<void> {
        if (editLoading) return;

        editLoading = true;
        error = "";

        try {
            editForm = (await requestFirstPartyApi<MonitoringFormOptions>(`/api/monitorings/${data.monitoring.id}/form-options`)).data;
            editOpen = true;
        } catch (exception) {
            error = exception instanceof FirstPartyApiError ? exception.message : "The monitoring editor could not be loaded.";
        } finally {
            editLoading = false;
        }
    }

    function closeEdit(): void {
        editOpen = false;
        editForm = null;
    }

    async function handleEditSuccess(_monitoring: MonitoringMutationResult): Promise<void> {
        closeEdit();
        await invalidateAll();
    }
</script>

<svelte:head><title>{data.monitoring.name} | WebGuard</title></svelte:head>

<Card.Root variant="toolbar">
    <header class="mx-auto w-[min(76rem,calc(100%_-_2rem))] py-6 sm:py-7">
        <a class="inline-flex items-center gap-2 text-sm font-bold text-wg-text-muted no-underline transition hover:text-wg-accent" href={appRoutes.monitorings}>
            <NavIcon name="arrow-left" size={16} />Back to monitorings
        </a>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <Badge variant="neutral" class="rounded-lg px-2.5 py-1 text-xs font-medium">{data.monitoring.type ?? "Monitoring"}</Badge>
            <Badge variant="outline" class={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium ${statusTone() === "healthy" ? "bg-wg-status-up text-wg-status-up-foreground" : statusTone() === "danger" ? "bg-wg-status-down text-wg-status-down-foreground" : "bg-wg-surface-muted text-wg-text-muted"}`}><Badge variant="dot" class="size-1.5" aria-hidden="true" />{statusLabel()}</Badge>
        </div>
        <h1 class="mt-3 break-words text-3xl leading-none font-extrabold tracking-tight sm:text-[2.15rem]">{data.monitoring.name}</h1>
        <div class="mt-4 flex flex-wrap items-center gap-3 text-sm text-wg-text-muted">
            <span class="break-all">{data.monitoring.target}</span>
            {#if linkedTarget}
                <Button variant="ghost" size="icon" class="min-h-0 border-transparent p-0 text-wg-accent enabled:hover:bg-violet-50 dark:enabled:hover:bg-violet-950/50" href={linkedTarget} target="_blank" rel="noreferrer" aria-label="Open target" title="Open target"><NavIcon name="external" size={16} /></Button>
            {/if}
            {#if data.monitoring.can_manage}
                <DropdownMenu.Root>
                    <DropdownMenu.Trigger
                        class={buttonVariants({ variant: "outline", size: "icon", class: "size-10 min-h-0 border-wg-focus p-0" })}
                        aria-label="Monitoring actions"
                        title="Monitoring actions"
                    >
                        <NavIcon name="ellipsis" size={18} />
                    </DropdownMenu.Trigger>
                    <DropdownMenu.Content variant="raised" class="w-48 p-1.5 text-wg-text" sideOffset={8} portalProps={{ to: "#localized-app" }}>
                        <DropdownMenu.Group>
                            <DropdownMenu.Item class="cursor-pointer px-3 py-2" disabled={editLoading} onSelect={openEdit}>Edit monitoring</DropdownMenu.Item>
                            <DropdownMenu.Item class="cursor-pointer px-3 py-2" disabled={resettingResults} onSelect={resetResults}>{translate("Reset results", data.locale, data.messages)}</DropdownMenu.Item>
                            <DropdownMenu.Item variant="destructive" class="cursor-pointer px-3 py-2" disabled={deleting} onSelect={remove}>Delete monitoring</DropdownMenu.Item>
                        </DropdownMenu.Group>
                    </DropdownMenu.Content>
                </DropdownMenu.Root>
            {/if}
        </div>
        {#if checkLocations.length > 0}
            <div class="mt-4 grid gap-2">
                <p class="text-xs font-extrabold tracking-wide text-wg-text-muted">Monitoring locations</p>
                <MonitoringLocationList locations={checkLocations} />
            </div>
        {/if}
    </header>
</Card.Root>

<main>
    <div class="mx-auto w-[min(76rem,calc(100%_-_2rem))] py-6 sm:py-7">
        {#if data.monitoring.latest_check === null && data.monitoring.initial_results_wait_minutes !== null && data.monitoring.initial_results_wait_minutes !== undefined}
            <Alert role="note" class="mb-6 rounded-xl border-blue-200 bg-blue-50 p-4 leading-6 text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100">The first check is scheduled on the next monitoring poll and usually starts within about {data.monitoring.initial_results_wait_minutes} {data.monitoring.initial_results_wait_minutes === 1 ? "minute" : "minutes"}. Queue pressure can delay it.</Alert>
        {/if}

        <section class="grid gap-4 md:grid-cols-3" aria-label="Monitoring summary">
            <Card.Root role="article" class={`md:col-span-3 p-5 sm:p-6`}>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><p class="text-sm font-medium text-wg-text-muted">Current status</p><div class="mt-2 flex flex-wrap items-center gap-3"><StatusBadge tone={statusTone()} label={statusLabel()} />{#if data.detail.current_check.status_code !== null}<span class="text-sm tabular-nums text-wg-text-muted">HTTP {data.detail.current_check.status_code}</span>{/if}</div><p class="mt-2 text-sm text-wg-text-muted">Since {timestamp(data.detail.current_check.checked_at)}</p></div>
                    {#if data.detail.maintenance.active}<div class="rounded-lg bg-wg-status-maintenance px-3 py-2 text-sm font-semibold text-wg-status-maintenance-foreground">{text("Maintenance in progress")}</div>{/if}
                </div>
                <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-wg-border pt-4 sm:grid-cols-4">
                    <div><dt class="text-sm text-wg-text-muted">{text("Uptime")} · {text("Last 90 days")}</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{availability("90")}</dd></div>
                    <div><dt class="text-sm text-wg-text-muted">{text("Average response time")}</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{data.detail.response_times.aggregated.avg === null ? "—" : `${formatNumber(Math.round(data.detail.response_times.aggregated.avg))} ms`}</dd></div>
                    <div><dt class="text-sm text-wg-text-muted">{text("Last 24 hours")}</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{lastDayAvailability() ?? "—"}</dd></div>
                    <div class="col-span-2 sm:col-span-1"><dt class="text-sm text-wg-text-muted">{text("Last check")}</dt><dd class="mt-1 text-base font-semibold tabular-nums">{timestamp(data.detail.current_check.checked_at)}</dd><p class="mt-1 text-xs text-wg-text-muted">{intervalLabel(data.detail.current_check.interval)}</p></div>
                </dl>
            </Card.Root>
        </section>

        {#if data.detail.server_health_telemetry}
            <ServerHealthOverview detail={data.detail} monitoringId={data.monitoring.id} />
        {/if}

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
            <div class="min-w-0 space-y-6">
                <section class="grid gap-4 md:grid-cols-3" aria-label="Availability periods">
                    {#each [["7", "Last 7 Days"], ["30", "Last 30 Days"], ["90", "Last 90 Days"]] as [days, label]}
                        <Card.Root role="article" class="min-h-36 p-6"><h2 class="text-2xl leading-none font-extrabold">{label}</h2><p class="mt-2 text-sm font-bold text-wg-accent">{availability(days as "7" | "30" | "90")}</p><p class="mt-2 text-sm leading-6 text-wg-text-muted">{periodDescription(days as "7" | "30" | "90")}</p></Card.Root>
                    {/each}
                </section>

    <MonitoringAnalytics detail={data.detail} monitoringId={data.monitoring.id} incidentsMeta={data.incidentsMeta} recentChecksMeta={data.recentChecksMeta} uptimeCalendarMeta={data.uptimeCalendarMeta} />
                <MonitoringTypeDiagnostics detail={data.detail} />
                {#if error}<Alert variant="destructive" class="text-sm font-bold">{error}</Alert>{/if}
            </div>

            <aside class="grid gap-4" aria-label="Monitoring context">
                <Card.Root as="section"><Card.Header class="block px-6 py-5"><Card.Title class="font-extrabold">Ownership and groups</Card.Title><p class="mt-1 text-sm text-wg-text-muted">Understand where this monitoring belongs.</p></Card.Header><Card.Content class="grid gap-5 p-6 text-sm"><div><p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-text-muted uppercase">Owner</p><p class="mt-1 font-extrabold">{data.detail.summary.ownership.name ?? "Private monitoring"}</p><p class="mt-1 text-wg-text-muted">{data.detail.summary.ownership.type === "team" ? "Team" : "Private"}</p></div><div><p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-text-muted uppercase">Groups</p>{#if data.detail.summary.groups.length > 0}<div class="mt-3 flex flex-wrap gap-2">{#each data.detail.summary.groups as group}<Badge variant="outline" class="rounded-full bg-violet-50 px-2.5 py-1 text-xs font-bold text-wg-accent dark:bg-violet-950/50">{group.name}</Badge>{/each}</div>{:else}<p class="mt-2 text-wg-text-muted">No groups</p>{/if}</div></Card.Content></Card.Root>
                {#if data.detail.ssl || data.detail.domain}<Card.Root as="section"><Card.Header class="block px-6 py-5"><Card.Title class="font-extrabold">Domain and SSL</Card.Title></Card.Header><Card.Content class="p-6">{#if data.detail.ssl}<div class="flex items-start justify-between gap-3"><div><p class="text-sm font-extrabold">SSL certificate</p><p class="mt-1 text-sm text-wg-text-muted">{data.detail.ssl.valid ? `Valid until ${timestamp(data.detail.ssl.expiration)}` : "Certificate validation failed"}</p>{#if data.detail.ssl.issuer}<p class="mt-3 text-[0.6875rem] font-extrabold tracking-[0.12em] text-wg-text-muted uppercase">Issuer</p><p class="mt-1 break-words text-sm font-bold">{data.detail.ssl.issuer}</p>{/if}</div><Badge variant="dot" class={`mt-1 size-2.5 ${data.detail.ssl.valid ? "bg-emerald-500" : "bg-red-500"}`} aria-hidden="true" /></div>{/if}{#if data.detail.domain}<div class="relative mt-5 pt-5"><Separator class="absolute inset-x-0 top-0 bg-wg-border" /><p class="text-sm font-extrabold">Domain registration</p><p class="mt-1 text-sm text-wg-text-muted">{data.detail.domain.valid ? `Valid until ${timestamp(data.detail.domain.expires_at)}` : "Domain validation failed"}</p>{#if data.detail.domain.registrar}<p class="mt-3 text-[0.6875rem] font-extrabold tracking-[0.12em] text-wg-text-muted uppercase">Registrar</p><p class="mt-1 break-words text-sm font-bold">{data.detail.domain.registrar}</p>{/if}</div>{/if}</Card.Content></Card.Root>{/if}
                <Card.Root as="section"><Card.Header class="block px-6 py-5"><Card.Title class="font-extrabold">Monitoring locations</Card.Title></Card.Header><Card.Content class="p-6">{#if checkLocations.length > 0}<MonitoringLocationList locations={checkLocations} />{:else}<EmptyDescription class="text-sm text-wg-text-muted">No specific regions configured.</EmptyDescription>{/if}</Card.Content></Card.Root>
                <Card.Root as="section"><Card.Header class="block px-6 py-5"><Card.Title class="font-extrabold">Next maintenance</Card.Title></Card.Header><Card.Content class="p-6">{#if data.detail.maintenance.starts_at}<p class="text-sm font-extrabold">{data.detail.maintenance.active ? "Maintenance active" : "Maintenance scheduled"}</p><p class="mt-1 text-sm text-wg-text-muted">Starts {timestamp(data.detail.maintenance.starts_at)}</p>{:else if data.detail.maintenance.has_recurring_window}<p class="text-sm text-wg-text-muted">A recurring maintenance window is configured.</p>{:else}<EmptyDescription class="text-sm text-wg-text-muted">No maintenance planned.</EmptyDescription>{/if}</Card.Content></Card.Root>
                <Card.Root as="section"><Card.Header class="block px-6 py-5"><Card.Title class="font-extrabold">Notifications</Card.Title></Card.Header><Card.Content class="p-6">{#if data.detail.summary.notification_channels.length > 0}<p class="text-[0.6875rem] font-extrabold tracking-[0.16em] text-wg-text-muted uppercase">Channels</p><div class="mt-3 flex flex-wrap gap-2">{#each data.detail.summary.notification_channels as channel}<Badge variant="neutral" class="rounded-full px-2.5 py-1 font-bold text-wg-text">{notificationChannelLabel(channel)}</Badge>{/each}</div>{:else}<EmptyDescription class="text-sm text-wg-text-muted">No channels enabled.</EmptyDescription>{/if}</Card.Content></Card.Root>
                <Card.Root as="section"><Card.Header class="block px-6 py-5"><Card.Title class="font-extrabold">Status pages</Card.Title></Card.Header><Card.Content class="p-6">{#if data.detail.summary.status_pages.length > 0}<ul class="m-0 grid list-none gap-2 p-0">{#each data.detail.summary.status_pages as statusPage}<li><a class="text-sm font-bold text-wg-accent no-underline hover:text-wg-accent-strong" href={appRoutes.statusPages}>{statusPage.name}</a></li>{/each}</ul>{:else}<p class="text-sm text-wg-text-muted">Not published on a status page.</p>{/if}</Card.Content></Card.Root>
            </aside>
        </div>
    </div>
</main>

{#if editForm}
    <Dialog bind:open={editOpen} title="Monitoring" description="Basics" size="wide" onclose={closeEdit}>
        <MonitoringForm
            options={editForm}
            action={`/api/monitorings/${editForm.monitoring?.id}`}
            method="PATCH"
            presentation="edit-modal"
            onSuccess={handleEditSuccess}
            onCancel={closeEdit}
        />
    </Dialog>
{/if}
