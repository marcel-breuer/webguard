<script lang="ts">
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import { formatDateTime, formatNumber } from "#lib/i18n/format.ts";
    import Button from "#lib/components/Button.svelte";
    import Card from "#lib/components/Card.svelte";
    import EmptyState from "#lib/components/EmptyState.svelte";
    import Select from "#lib/components/Select.svelte";
    import StatusBadge from "#lib/components/StatusBadge.svelte";
    import type { NotificationEntry, NotificationInboxMeta } from "./+page.server";

    interface Props { data: { entries: NotificationEntry[]; meta: NotificationInboxMeta }; }
    let { data }: Props = $props();
    let entries = $state<NotificationEntry[]>([]);
    let meta = $state<NotificationInboxMeta>({ next_cursor: null, has_more: false, unread_count: 0 });
    let showRead = $state(false);
    let eventType = $state("");
    let loading = $state(false);
    let markingAllRead = $state(false);
    let markingRead = $state<string | null>(null);
    let errorMessage = $state("");

    function publishUnreadNotificationCount(count: number): void {
        window.dispatchEvent(new CustomEvent("webguard:notifications-count", { detail: count }));
    }

    $effect(() => {
        entries = data.entries;
        meta = data.meta;
    });

    const eventLabels: Record<string, string> = {
        incident: "Incident",
        recovery: "Recovery",
        maintenance: "Maintenance",
        performance_degraded: "Performance Degraded",
        performance_recovered: "Performance Recovered",
        ssl_expiring: "SSL Expiring",
        ssl_expired: "SSL Expired",
        domain_expiring: "Domain Expiring",
        domain_expired: "Domain Expired",
        delivery_failure: "Delivery Failure",
    };

    const eventTypes = [
        ["", "All events"],
        ["incident", "Incidents"],
        ["recovery", "Recoveries"],
        ["maintenance", "Maintenance"],
        ["performance_degraded", "Performance degraded"],
        ["performance_recovered", "Performance recovered"],
        ["ssl_expiring", "SSL expiring"],
        ["ssl_expired", "SSL expired"],
        ["domain_expiring", "Domain expiring"],
        ["domain_expired", "Domain expired"],
        ["delivery_failure", "Delivery failures"],
    ] as const;

    function formatDate(value: string | null): string {
        return formatDateTime(value, "Unknown time");
    }

    function eventLabel(entry: NotificationEntry): string {
        return eventLabels[entry.event_type] ?? entry.event_type.split("_").map((word) => word[0].toUpperCase() + word.slice(1)).join(" ");
    }

    function hasTechnicalMessage(entry: NotificationEntry): boolean {
        return entry.message === entry.event_type.toUpperCase();
    }

    function tone(entry: NotificationEntry): "healthy" | "degraded" | "danger" | "neutral" {
        if (entry.severity === "critical") return "danger";
        if (entry.severity === "warning") return "degraded";
        return "neutral";
    }

    async function load(reset: boolean): Promise<void> {
        loading = true;
        errorMessage = "";
        const params = new URLSearchParams({ limit: "5", show_read: showRead ? "1" : "0" });
        if (eventType) params.set("event_type", eventType);
        if (!reset && meta.next_cursor) params.set("cursor", meta.next_cursor);

        try {
            const payload = await requestFirstPartyApi<NotificationEntry[], NotificationInboxMeta>(`/api/notifications?${params.toString()}`);
            if (!payload.meta) {
                throw new Error("Notification metadata is missing.");
            }
            entries = reset ? payload.data : [...entries, ...payload.data];
            meta = payload.meta;
        } catch (error) {
            errorMessage = error instanceof FirstPartyApiError ? error.message : "Notifications could not be loaded.";
        } finally {
            loading = false;
        }
    }

    async function markRead(entry: NotificationEntry): Promise<void> {
        markingRead = entry.id;
        errorMessage = "";

        try {
            const payload = await requestFirstPartyApi<{ read_notification_ids: string[] }, { unread_count: number }>(
                `/api/notifications/${entry.id}/read`,
                { method: "PATCH" },
            );
            entries = showRead
                ? entries.map((currentEntry) => payload.data.read_notification_ids.includes(currentEntry.id)
                    ? { ...currentEntry, read: true }
                    : currentEntry)
                : entries.filter((currentEntry) => !payload.data.read_notification_ids.includes(currentEntry.id));
            const unreadCount = payload.meta?.unread_count ?? meta.unread_count;
            meta = { ...meta, unread_count: unreadCount };
            publishUnreadNotificationCount(unreadCount);
        } catch (error) {
            errorMessage = error instanceof FirstPartyApiError ? error.message : "The notification could not be marked as read.";
        } finally {
            markingRead = null;
        }
    }

    async function markAllRead(): Promise<void> {
        markingAllRead = true;
        errorMessage = "";

        try {
            const payload = await requestFirstPartyApi<{ read: boolean }, { unread_count: number }>(
                "/api/notifications/read-all",
                { method: "PATCH" },
            );
            entries = showRead ? entries.map((entry) => ({ ...entry, read: true })) : [];
            const unreadCount = payload.meta?.unread_count ?? meta.unread_count;
            meta = {
                ...meta,
                has_more: showRead ? meta.has_more : false,
                next_cursor: showRead ? meta.next_cursor : null,
                unread_count: unreadCount,
            };
            publishUnreadNotificationCount(unreadCount);
        } catch (error) {
            errorMessage = error instanceof FirstPartyApiError ? error.message : "Notifications could not be marked as read.";
        } finally {
            markingAllRead = false;
        }
    }
</script>

<svelte:head><title>Notifications | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(70rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8 flex flex-col items-start justify-between gap-4 lg:flex-row lg:items-end">
        <div><p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Inbox</p><h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Notifications</h1><p class="mt-3 max-w-2xl leading-6 text-wg-text-muted">{formatNumber(meta.unread_count)} unread {meta.unread_count === 1 ? "notification" : "notifications"} across your accessible monitorings.</p></div>
        <Button variant="secondary" loading={markingAllRead} disabled={meta.unread_count === 0} onclick={markAllRead}>Mark all as read</Button>
    </header>

    <Card>
        <div class="flex flex-col gap-4 border-b border-wg-border pb-5 sm:flex-row sm:items-end sm:justify-between">
            <div class="grid gap-2"><span class="text-sm font-bold">Show</span><div class="flex flex-wrap gap-2"><Button variant={showRead ? "secondary" : "primary"} onclick={() => { showRead = false; load(true); }}>Unread</Button><Button variant={showRead ? "primary" : "secondary"} onclick={() => { showRead = true; load(true); }}>All</Button></div></div>
            <label class="grid gap-2 text-sm font-bold">Event type<Select value={eventType} onchange={(event) => { eventType = event.currentTarget.value; load(true); }}>{#each eventTypes as [value, label]}<option {value}>{label}</option>{/each}</Select></label>
        </div>

        {#if errorMessage}<p class="mt-5 text-sm font-semibold text-wg-danger" role="alert">{errorMessage}</p>{/if}

        {#if entries.length === 0 && !loading}
            <div class="mt-6"><EmptyState title={showRead ? "No notifications yet" : "No unread notifications"} description={showRead ? "New monitoring events and delivery failures will appear here." : "You're all caught up. Switch to all notifications to review earlier events."} /></div>
        {:else}
            <div class="mt-6 divide-y divide-wg-border rounded-xl border border-wg-border">
                {#each entries as entry (entry.id)}
                    <article class={`grid gap-4 p-4 sm:grid-cols-[1fr_auto] sm:items-center ${entry.read ? "opacity-70" : ""}`}>
                        <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><StatusBadge tone={tone(entry)} label={eventLabel(entry)} />{#if entry.delivery_status === "failed"}<StatusBadge tone="degraded" label="Delivery failed" />{/if}{#if entry.read}<span class="text-xs font-bold text-wg-text-muted">Read</span>{/if}</div><h2 class="mt-3 text-base font-bold"><a class="text-wg-text no-underline hover:text-wg-accent hover:underline" href={`/monitorings/${entry.monitoring.id}`}>{entry.monitoring.name}</a></h2>{#if !hasTechnicalMessage(entry)}<p class="mt-1 text-sm leading-6 text-wg-text-muted">{entry.message}</p>{/if}<p class="mt-2 text-xs font-semibold tracking-[0.03em] text-wg-text-muted"><a class="text-wg-text-muted underline decoration-wg-border underline-offset-2 hover:text-wg-accent" href={entry.monitoring.target} target="_blank" rel="noopener noreferrer">{entry.monitoring.target}</a> · {formatDate(entry.occurred_at)}</p></div>
                        {#if !entry.read}<Button variant="quiet" loading={markingRead === entry.id} onclick={() => markRead(entry)}>Mark as read</Button>{/if}
                    </article>
                {/each}
            </div>
            {#if meta.has_more}<div class="mt-6 flex justify-center"><Button variant="secondary" loading={loading} onclick={() => load(false)}>Load more</Button></div>{/if}
        {/if}
    </Card>
</main>
