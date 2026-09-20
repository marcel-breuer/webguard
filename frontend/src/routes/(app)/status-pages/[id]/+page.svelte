<script lang="ts">
    import { invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "$lib/api/client";
    import { formatDateTime } from "$lib/i18n/format";
    import type { StatusPage, StatusPageIncident } from "$lib/api/status-pages";
    import Button from "$lib/components/Button.svelte";
    import Card from "$lib/components/Card.svelte";
    import Dialog from "$lib/components/Dialog.svelte";
    import Field from "$lib/components/Field.svelte";
    import Input from "$lib/components/Input.svelte";
    import Select from "$lib/components/Select.svelte";
    import StatusBadge from "$lib/components/StatusBadge.svelte";
    import Textarea from "$lib/components/Textarea.svelte";

    interface Props { data: { statusPage: { data: StatusPage }; incidents: { data: StatusPageIncident[] } }; }
    let { data }: Props = $props();
    const statusPage = $derived(data.statusPage.data);
    const announcement = $derived(statusPage.announcement);
    let publishOpen = $state(false);
    let announcementOpen = $state(false);
    let selectedIncident = $state<StatusPageIncident | null>(null);
    let updatingPublication = $state(false);
    let publishingUpdate = $state(false);
    let publishingAnnouncement = $state(false);
    let dismissingAnnouncement = $state(false);
    let error = $state("");
    let message = $state("");

    function dateTime(value: string | null): string {
        return formatDateTime(value, "Not recorded");
    }

    function incidentStatusLabel(state: StatusPageIncident["lifecycle"]["state"]): string {
        return state === "open" ? "Open incident" : "Resolved incident";
    }

    function updateStatusLabel(status: string): string {
        return status.charAt(0).toUpperCase() + status.slice(1);
    }

    async function updatePublication(): Promise<void> {
        if (updatingPublication || publishingUpdate) {
            return;
        }

        updatingPublication = true;
        error = "";
        message = "";
        try {
            await requestFirstPartyApi(`/api/status-pages/${statusPage.id}/publication`, { body: JSON.stringify({ is_public: !statusPage.publication.is_public }), method: "PATCH" });
            message = statusPage.publication.is_public ? "Status page unpublished." : "Status page published.";
            await invalidateAll();
        } catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : "The publication state could not be updated."; } finally { updatingPublication = false; }
    }

    async function publishIncidentUpdate(event: SubmitEvent): Promise<void> {
        event.preventDefault();

        if (!selectedIncident || publishingUpdate || updatingPublication) {
            return;
        }

        publishingUpdate = true;
        error = "";
        try {
            await requestFirstPartyApi(`/api/status-pages/${statusPage.id}/incidents/${selectedIncident.id}/updates`, {
                body: new FormData(event.currentTarget as HTMLFormElement),
                headers: { "Idempotency-Key": crypto.randomUUID() },
                method: "POST",
            });
            publishOpen = false;
            selectedIncident = null;
            message = "Incident update published.";
            await invalidateAll();
        } catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : "The incident update could not be published."; } finally { publishingUpdate = false; }
    }

    async function saveAnnouncement(event: SubmitEvent): Promise<void> {
        event.preventDefault();

        if (publishingAnnouncement || dismissingAnnouncement || updatingPublication) {
            return;
        }

        publishingAnnouncement = true;
        error = "";
        try {
            await requestFirstPartyApi(
                announcement ? `/api/status-pages/${statusPage.id}/announcements/${announcement.id}` : `/api/status-pages/${statusPage.id}/announcements`,
                { body: new FormData(event.currentTarget as HTMLFormElement), method: announcement ? "PATCH" : "POST" },
            );
            announcementOpen = false;
            message = announcement ? "Announcement updated." : "Announcement published.";
            await invalidateAll();
        } catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : "The announcement could not be saved."; } finally { publishingAnnouncement = false; }
    }

    async function dismissAnnouncement(): Promise<void> {
        if (!announcement || dismissingAnnouncement || publishingAnnouncement || !globalThis.confirm("Dismiss this status page announcement?")) {
            return;
        }

        dismissingAnnouncement = true;
        error = "";
        try {
            await requestFirstPartyApi(`/api/status-pages/${statusPage.id}/announcements/${announcement.id}`, { method: "DELETE" });
            message = "Announcement dismissed.";
            await invalidateAll();
        } catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : "The announcement could not be dismissed."; } finally { dismissingAnnouncement = false; }
    }
</script>

<svelte:head><title>{statusPage.name} | Status pages | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(70rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <a class="text-sm font-bold text-wg-accent no-underline" href="/status-pages">← Status pages</a>
    <header class="mt-5 mb-8 flex flex-col items-start justify-between gap-4 sm:flex-row"><div><div class="flex flex-wrap items-center gap-3"><h1 class="text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">{statusPage.name}</h1><StatusBadge tone={statusPage.publication.is_public ? "healthy" : "paused"} label={statusPage.publication.is_public ? "Public" : "Private"} /></div>{#if statusPage.description}<p class="mt-3 max-w-2xl leading-6 text-wg-text-muted">{statusPage.description}</p>{/if}</div><div class="flex flex-wrap gap-3">{#if statusPage.publication.is_public}<a class="inline-flex min-h-11 items-center justify-center rounded-xl border border-wg-border px-4 py-2.5 text-sm font-bold text-wg-text no-underline" href={`/status/${statusPage.id}`} target="_blank" rel="noreferrer">Open public page</a>{/if}<Button variant="secondary" loading={updatingPublication} disabled={publishingUpdate} onclick={updatePublication}>{statusPage.publication.is_public ? "Unpublish" : "Publish"}</Button></div></header>
    {#if message}<p class="mb-6 text-sm font-bold text-green-700 dark:text-green-300" role="status">{message}</p>{/if}{#if error}<p class="mb-6 text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}
    <section class="mb-6"><Card title="Announcement" description={statusPage.publication.is_public ? "Share a proactive update without opening an incident." : "Publish this status page before sharing an announcement."}>{#if announcement}<div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100"><div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="font-bold">{announcement.title}</h2><p class="mt-2 whitespace-pre-line text-sm leading-6">{announcement.message}</p>{#if announcement.notify_subscribers}<p class="mt-3 text-xs font-bold text-sky-800 dark:text-sky-200">{announcement.notified_at ? `Sent to verified subscribers ${dateTime(announcement.notified_at)}.` : "Queued for verified subscribers."}</p>{/if}</div><div class="flex gap-2"><Button class="min-h-10 px-3 py-1.5" variant="secondary" type="button" disabled={publishingAnnouncement || dismissingAnnouncement} onclick={() => (announcementOpen = true)}>Edit</Button><Button class="min-h-10 px-3 py-1.5" variant="danger" type="button" loading={dismissingAnnouncement} disabled={publishingAnnouncement} onclick={dismissAnnouncement}>Dismiss</Button></div></div></div>{:else}<Button type="button" disabled={!statusPage.publication.is_public} onclick={() => (announcementOpen = true)}>Publish announcement</Button>{/if}</Card></section>
    <section class="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]"><Card title="Components" description={`${statusPage.verified_subscriber_count} verified subscribers`}><div class="grid gap-4">{#each statusPage.components as component (component.id)}<article class="border-b border-wg-border pb-4 last:border-b-0"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-bold">{component.name}</h2><span class="text-sm font-bold text-wg-text-muted">{component.monitorings.length} monitorings</span></div>{#if component.description}<p class="mt-1 text-sm leading-5 text-wg-text-muted">{component.description}</p>{/if}{#if component.monitoring_group}<p class="mt-3 text-sm text-wg-text-muted">Monitoring group: <span class="font-bold text-wg-text">{component.monitoring_group.name}</span></p>{/if}<ul class="mt-3 grid gap-2 p-0">{#each component.monitorings as monitoring (monitoring.id)}<li class="list-none rounded-xl bg-wg-surface-muted px-3 py-2 text-sm"><span class="font-bold">{monitoring.name}</span><span class="ml-2 text-wg-text-muted">{monitoring.target}</span></li>{/each}</ul></article>{/each}</div></Card>
        <Card title="Incidents" description="Publish clear updates as incidents are investigated and resolved.">{#if data.incidents.data.length > 0}<div class="grid gap-4">{#each data.incidents.data as incident (incident.id)}<article class="rounded-xl border border-wg-border p-4"><div class="flex flex-wrap items-start justify-between gap-3"><div><div class="flex items-center gap-2"><h2 class="font-bold">{incident.monitoring.name}</h2><StatusBadge tone={incident.lifecycle.state === "open" ? "danger" : "healthy"} label={incidentStatusLabel(incident.lifecycle.state)} /></div><p class="mt-1 text-sm text-wg-text-muted">Opened {dateTime(incident.lifecycle.opened_at)}</p></div><Button class="min-h-10 px-3 py-1.5" variant="secondary" type="button" onclick={() => { selectedIncident = incident; publishOpen = true; }}>Publish update</Button></div>{#if incident.readiness.requires_public_update}<p class="mt-3 text-sm font-bold text-amber-700 dark:text-amber-300">This open incident has no public update yet.</p>{/if}{#if incident.updates.length > 0}<ol class="mt-4 grid gap-3 p-0">{#each incident.updates as update (update.id)}<li class="list-none border-l-2 border-wg-accent pl-3"><p class="text-sm font-bold">{updateStatusLabel(update.status)}</p><p class="mt-1 text-sm leading-5 text-wg-text-muted">{update.message}</p><p class="mt-1 text-xs text-wg-text-muted">{dateTime(update.published_at)}</p></li>{/each}</ol>{/if}</article>{/each}</div>{:else}<p class="text-sm leading-6 text-wg-text-muted">No incidents are associated with these components.</p>{/if}</Card></section>
</main>

<Dialog bind:open={announcementOpen} title={announcement ? "Edit announcement" : "Publish announcement"} description={announcement ? "Update the visible announcement without sending another email." : "Publish a visible update without opening an incident."}>{#if statusPage.publication.is_public}<form class="grid gap-5" onsubmit={saveAnnouncement} novalidate><Field label="Title" required><Input name="title" maxlength={120} required value={announcement?.title ?? ""} /></Field><Field label="Message" required><Textarea class="min-h-32" name="message" maxlength={2000} required value={announcement?.message ?? ""} /></Field>{#if !announcement}<input name="notify_subscribers" type="hidden" value="0" /><label class="flex items-start gap-3 rounded-xl border border-wg-border p-3 text-sm"><input class="mt-1 size-4 accent-wg-accent" name="notify_subscribers" type="checkbox" value="1" /><span><span class="block font-bold">Notify verified subscribers</span><span class="mt-1 block leading-5 text-wg-text-muted">Send this announcement once to confirmed email subscribers.</span></span></label>{/if}<Button type="submit" loading={publishingAnnouncement}>{announcement ? "Save announcement" : "Publish announcement"}</Button></form>{/if}</Dialog>

<Dialog bind:open={publishOpen} title="Publish incident update" description={selectedIncident ? `Share an update for ${selectedIncident.monitoring.name}.` : ""}>{#if selectedIncident}<form class="grid gap-5" onsubmit={publishIncidentUpdate} novalidate><Field label="Status" required><Select name="status" required><option value="investigating">Investigating</option><option value="identified">Identified</option><option value="monitoring">Monitoring</option><option value="resolved">Resolved</option></Select></Field><Field label="Message" required><Textarea class="min-h-32" name="message" required /></Field><Button type="submit" loading={publishingUpdate}>Publish update</Button></form>{/if}</Dialog>
