<script lang="ts">
    import { invalidateAll } from "$app/navigation";
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import type { MonitoringFormOptions } from "#lib/api/monitoring.ts";
    import Button from "#lib/components/Button.svelte";
    import Card from "#lib/components/Card.svelte";
    import Checkbox from "#lib/components/Checkbox.svelte";
    import Field from "#lib/components/Field.svelte";
    import Input from "#lib/components/Input.svelte";
    import Select from "#lib/components/Select.svelte";

    interface Preferences {
        effective: { notification_on_failure: boolean; notification_channels: string[]; ssl_expiry_warning_days: number; };
        permitted_channels: string[];
        can_update: boolean;
    }
    interface Props { options: MonitoringFormOptions; preferences: Preferences; }
    let { options, preferences }: Props = $props();
    const monitoring = $derived(options.monitoring);
    let selectedChannels = $state<string[]>([]);
    let notificationsSaving = $state(false);
    let ownershipSaving = $state(false);
    let message = $state("");
    let error = $state("");

    $effect(() => {
        selectedChannels = [...preferences.effective.notification_channels];
    });

    async function updateNotifications(event: SubmitEvent): Promise<void> {
        event.preventDefault();

        if (!monitoring || notificationsSaving) {
            return;
        }

        notificationsSaving = true;
        message = "";
        error = "";
        try {
            await requestFirstPartyApi(`/api/monitorings/${monitoring.id}/notification-preferences`, { body: new FormData(event.currentTarget as HTMLFormElement), method: "PATCH" });
            message = "Notification preferences saved.";
        } catch (exception) {
            error = exception instanceof FirstPartyApiError ? exception.message : "Notification preferences could not be saved.";
        } finally { notificationsSaving = false; }
    }

    async function updateOwnership(event: SubmitEvent): Promise<void> {
        event.preventDefault();

        if (!monitoring || ownershipSaving) {
            return;
        }

        ownershipSaving = true;
        message = "";
        error = "";
        const form = event.currentTarget as HTMLFormElement;
        const teamId = new FormData(form).get("team_id");
        const path = teamId ? "ownership/team" : "ownership/private";
        try {
            await requestFirstPartyApi(`/api/monitorings/${monitoring.id}/${path}`, { body: new FormData(form), method: "POST" });
            await invalidateAll();
        } catch (exception) {
            error = exception instanceof FirstPartyApiError ? exception.message : "Monitoring ownership could not be updated.";
        } finally { ownershipSaving = false; }
    }
</script>

{#if monitoring}<section class="mt-6 grid gap-6 xl:grid-cols-2"><Card title="Ownership" description="Choose whether this monitoring is private or administered by a team."><form class="grid gap-4" onsubmit={updateOwnership}><Field label="Owner"><Select name="team_id"><option value="" selected={monitoring.ownership.type === "private"}>Private monitoring</option>{#each options.teams as team}<option value={team.id} selected={monitoring.ownership.team_id === team.id}>Team: {team.name}</option>{/each}</Select></Field><Button type="submit" loading={ownershipSaving}>Save ownership</Button></form></Card><Card title="Notification preferences" description="Control your effective alert settings for this monitoring."><form class="grid gap-4" onsubmit={updateNotifications}><Input name="notification_on_failure" type="hidden" value="0" /><label class="flex items-center gap-2 text-sm font-bold"><Checkbox name="notification_on_failure" value="1" checked={preferences.effective.notification_on_failure} /> Notify on failure</label><fieldset class="grid gap-2"><legend class="text-sm font-bold">Channels</legend>{#if preferences.permitted_channels.length > 0}{#each preferences.permitted_channels as channel}<label class="flex items-center gap-2 text-sm"><Checkbox name="notification_channels[]" value={channel} bind:group={selectedChannels} />{channel}</label>{/each}{:else}<p class="text-sm text-wg-text-muted">No notification channels are configured.</p>{/if}</fieldset><Field label="SSL warning days"><Input name="ssl_expiry_warning_days" type="number" min="1" max="365" value={preferences.effective.ssl_expiry_warning_days} required /></Field><Button type="submit" loading={notificationsSaving} disabled={!preferences.can_update}>Save notification preferences</Button></form></Card></section>{#if message}<p class="mt-4 text-sm font-bold text-green-700 dark:text-green-300" role="status">{message}</p>{/if}{#if error}<p class="mt-4 text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}{/if}
