<script lang="ts">
    import { onDestroy } from "svelte";
    import { FirstPartyApiError, requestFirstPartyApi } from "$lib/api/client";
    import Button from "$lib/components/Button.svelte";
    import Checkbox from "$lib/components/Checkbox.svelte";
    import Field from "$lib/components/Field.svelte";
    import Input from "$lib/components/Input.svelte";
    import Textarea from "$lib/components/Textarea.svelte";

    interface Assignment { id: string; name: string; target: string; }
    interface Group { id: string; name: string; description: string | null; sla: { target_percentage: number | null; monthly_reports_enabled: boolean }; assignments: Assignment[]; }
    interface Props {
        action: string;
        method: "POST" | "PATCH";
        assignments: Assignment[];
        group?: Group | null;
        onSuccess: () => void;
    }

    let { action, method, assignments, group = null, onSuccess }: Props = $props();
    let selectedMonitoringIds = $state<string[]>([]);
    let availableAssignments = $state<Assignment[]>([]);
    let search = $state("");
    let submitting = $state(false);
    let searching = $state(false);
    let error = $state("");
    let searchError = $state("");
    let errors = $state<Record<string, string[]>>({});
    let searchTimer: number | undefined;
    let searchRequest = 0;

    onDestroy(() => {
        if (searchTimer !== undefined) {
            window.clearTimeout(searchTimer);
        }
    });

    $effect(() => {
        selectedMonitoringIds = group?.assignments.map((monitoring) => monitoring.id) ?? [];
    });

    $effect(() => {
        availableAssignments = mergeAssignments(assignments, group?.assignments ?? []);
    });

    function mergeAssignments(...assignmentLists: Assignment[][]): Assignment[] {
        return Array.from(new Map(assignmentLists.flat().map((assignment) => [assignment.id, assignment])).values());
    }

    function errorFor(field: string): string | undefined { return errors[field]?.[0]; }

    function scheduleSearch(): void {
        if (searchTimer !== undefined) {
            window.clearTimeout(searchTimer);
        }

        searchTimer = window.setTimeout(() => void loadAssignments(), 250);
    }

    async function loadAssignments(): Promise<void> {
        const requestId = ++searchRequest;
        const query = search.trim();
        const params = new URLSearchParams({ per_page: "100" });

        if (query !== "") {
            params.set("search", query);
        }

        searching = true;
        searchError = "";

        try {
            const response = await requestFirstPartyApi<Assignment[]>(`/api/monitoring-groups/assignment-options?${params.toString()}`);

            if (requestId === searchRequest) {
                availableAssignments = mergeAssignments(response.data, group?.assignments ?? []);
            }
        } catch (exception) {
            if (requestId === searchRequest) {
                searchError = exception instanceof FirstPartyApiError ? exception.message : "The monitorings could not be searched.";
            }
        } finally {
            if (requestId === searchRequest) {
                searching = false;
            }
        }
    }

    async function submit(event: SubmitEvent): Promise<void> {
        event.preventDefault();

        if (submitting) {
            return;
        }

        submitting = true;
        error = "";
        errors = {};

        try {
            await requestFirstPartyApi(action, { body: new FormData(event.currentTarget as HTMLFormElement), method });
            await onSuccess();
        } catch (exception) {
            if (exception instanceof FirstPartyApiError) {
                error = exception.message;
                errors = exception.errors;
            } else {
                error = "The monitoring group could not be saved.";
            }
        } finally {
            submitting = false;
        }
    }
</script>

<form class="grid gap-5" onsubmit={submit} novalidate>
    <Field label="Name" error={errorFor("name")} required><Input name="name" value={group?.name ?? ""} required /></Field>
    <Field label="Description" error={errorFor("description")}><Textarea class="min-h-28" name="description" value={group?.description ?? ""} /></Field>
    <fieldset class="grid gap-3 rounded-xl border border-wg-border p-4"><legend class="px-1 text-sm font-bold">SLA reporting</legend><Field label="Monthly uptime target (%)" hint="Unknown monitoring time is excluded. Planned maintenance is excluded from the measured period."><Input name="sla_target_percentage" type="number" min="0" max="100" step="0.01" value={group?.sla.target_percentage ?? ""} /></Field><input name="sla_report_enabled" type="hidden" value="0" /><label class="flex min-h-11 items-center gap-3 text-sm font-bold"><Checkbox name="sla_report_enabled" value="1" checked={group?.sla.monthly_reports_enabled ?? false} /> Email the previous month's report on the first day of each month</label></fieldset>
    <fieldset class="grid gap-2"><legend class="text-sm font-bold">Private monitorings</legend><p class="text-sm leading-6 text-wg-text-muted">Groups can contain monitorings that you privately own.</p><Input type="search" bind:value={search} aria-label="Search private monitorings" placeholder="Search by name or target" oninput={scheduleSearch} /><div class="max-h-56 overflow-y-auto rounded-lg border border-wg-border p-3" aria-busy={searching}>{#if searching}<p class="text-sm text-wg-text-muted">Searching monitorings...</p>{:else if availableAssignments.length > 0}{#each availableAssignments as monitoring}<label class="flex min-h-10 items-center gap-3 border-b border-wg-border py-2 text-sm last:border-b-0"><Checkbox name="monitoring_ids[]" value={monitoring.id} bind:group={selectedMonitoringIds} /><span class="min-w-0"><span class="block font-bold">{monitoring.name}</span><span class="block truncate text-wg-text-muted">{monitoring.target}</span></span></label>{/each}{:else}<p class="text-sm text-wg-text-muted">{search === "" ? "No private monitorings are available." : "No matching private monitorings found."}</p>{/if}</div>{#if searchError}<p class="text-sm text-wg-danger" role="alert">{searchError}</p>{/if}{#if errorFor("monitoring_ids")}<p class="text-sm text-wg-danger">{errorFor("monitoring_ids")}</p>{/if}</fieldset>
    {#if error}<p class="text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}
    <Button type="submit" loading={submitting}>{group ? "Save group" : "Create group"}</Button>
</form>
