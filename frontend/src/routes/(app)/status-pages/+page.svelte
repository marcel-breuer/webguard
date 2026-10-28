<script lang="ts">
    import { formatNumber } from "#lib/i18n/format.ts";
    import { goto, invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import type { StatusPage, StatusPageMonitoring, StatusPageMonitoringGroup } from "#lib/api/status-pages.ts";
    import Button from "#lib/components/Button.svelte";
    import Card from "#lib/components/Card.svelte";
    import Dialog from "#lib/components/Dialog.svelte";
    import EmptyState from "#lib/components/EmptyState.svelte";
    import StatusBadge from "#lib/components/StatusBadge.svelte";
    import StatusPageForm from "#lib/components/StatusPageForm.svelte";

    interface Props { data: { statusPages: { data: StatusPage[] }; options: { data: { monitorings: StatusPageMonitoring[]; monitoring_groups: StatusPageMonitoringGroup[] } } }; }
    let { data }: Props = $props();
    let createOpen = $state(false);
    let editOpen = $state(false);
    let editing = $state<StatusPage | null>(null);
    let error = $state("");
    let actionPending = $state(false);

    async function reload(): Promise<void> {
        createOpen = false;
        editOpen = false;
        editing = null;
        await invalidateAll();
    }

    async function edit(statusPage: StatusPage): Promise<void> {
        if (actionPending) {
            return;
        }

        actionPending = true;
        error = "";
        try {
            editing = (await requestFirstPartyApi<StatusPage>(`/api/status-pages/${statusPage.id}`)).data;
            editOpen = true;
        } catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : "The status page could not be loaded."; } finally { actionPending = false; }
    }

    async function remove(statusPage: StatusPage): Promise<void> {
        if (actionPending) {
            return;
        }

        if (!globalThis.confirm(`Delete ${statusPage.name}? This cannot be undone.`)) return;

        actionPending = true;
        error = "";
        try {
            await requestFirstPartyApi(`/api/status-pages/${statusPage.id}`, { method: "DELETE" });
            await reload();
        } catch (exception) { error = exception instanceof FirstPartyApiError ? exception.message : "The status page could not be deleted."; } finally { actionPending = false; }
    }
</script>

<svelte:head><title>Status pages | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(70rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8 flex flex-col items-start justify-between gap-4 sm:flex-row"><div><p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Operations</p><h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Status pages</h1><p class="mt-3 leading-6 text-wg-text-muted">Publish a clear, component-based status view for your customers.</p></div><Button onclick={() => (createOpen = true)}>Create status page</Button></header>
    {#if error}<p class="mb-6 text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}
    {#if data.statusPages.data.length === 0}<EmptyState title="No status pages yet" description="Create a status page to share service availability with subscribers." />{:else}<section class="grid grid-cols-[repeat(auto-fit,minmax(18rem,1fr))] gap-4">{#each data.statusPages.data as statusPage (statusPage.id)}<Card title={statusPage.name} description={statusPage.description ?? "No description provided."}>{#snippet actions()}<div class="flex items-center gap-2"><StatusBadge tone={statusPage.publication.is_public ? "healthy" : "paused"} label={statusPage.publication.is_public ? "Public" : "Private"} /><span class="text-sm font-bold text-wg-text-muted">{formatNumber(statusPage.component_count)} components</span></div>{/snippet}<div class="flex flex-wrap gap-3 md:flex-nowrap"><Button class="min-h-10 px-3 py-1.5" variant="secondary" type="button" onclick={() => goto(`/status-pages/${statusPage.id}`)}>Open</Button>{#if statusPage.publication.is_public}<a class="inline-flex min-h-10 items-center justify-center rounded-md border border-wg-border px-3 py-1.5 text-sm font-bold text-wg-text no-underline" href={`/status/${statusPage.id}`} target="_blank" rel="noreferrer">Public page</a>{/if}<Button class="min-h-10 px-3 py-1.5" variant="secondary" type="button" disabled={actionPending} aria-busy={actionPending} onclick={() => edit(statusPage)}>Edit</Button><Button class="min-h-10 px-3 py-1.5" variant="danger" type="button" disabled={actionPending} aria-busy={actionPending} onclick={() => remove(statusPage)}>Delete</Button></div></Card>{/each}</section>{/if}
</main>

<Dialog bind:open={createOpen} title="Create status page" description="Add service components without nested panels or a second submit."><StatusPageForm action="/api/status-pages" method="POST" monitorings={data.options.data.monitorings} monitoringGroups={data.options.data.monitoring_groups} onSuccess={reload} /></Dialog>
<Dialog bind:open={editOpen} title="Edit status page" description="Update status-page details and components in one request.">{#if editing}<StatusPageForm action={`/api/status-pages/${editing.id}`} method="PATCH" statusPage={editing} monitorings={data.options.data.monitorings} monitoringGroups={data.options.data.monitoring_groups} onSuccess={reload} />{/if}</Dialog>
