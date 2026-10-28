<script lang="ts">
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import type { StatusPage, StatusPageMonitoring, StatusPageMonitoringGroup } from "#lib/api/status-pages.ts";
    import Button from "#lib/components/Button.svelte";
    import Checkbox from "#lib/components/Checkbox.svelte";
    import Field from "#lib/components/Field.svelte";
    import Input from "#lib/components/Input.svelte";
    import Select, { type SelectOption } from "#lib/components/Select.svelte";
    import { formatNumber } from "#lib/i18n/format.ts";
    import Textarea from "#lib/components/Textarea.svelte";

    interface FormComponent {
        id?: string;
        name: string;
        description: string;
        sourceType: "manual" | "monitoring_group";
        monitoringGroupId: string;
        monitoringIds: string[];
    }
    interface Props {
        action: string;
        method: "POST" | "PATCH";
        monitorings: StatusPageMonitoring[];
        monitoringGroups: StatusPageMonitoringGroup[];
        statusPage?: StatusPage | null;
        onSuccess: (statusPage: StatusPage) => void | Promise<void>;
    }

    let { action, method, monitorings, monitoringGroups, statusPage = null, onSuccess }: Props = $props();
    let components = $state<FormComponent[]>([]);
    let submitting = $state(false);
    let error = $state("");
    let errors = $state<Record<string, string[]>>({});

    const monitoringOptions = $derived<SelectOption[]>(monitorings.map((monitoring) => ({
        value: monitoring.id,
        label: `${monitoring.name} · ${monitoring.target}`,
    })));
    const monitoringGroupOptions = $derived<SelectOption[]>(monitoringGroups.map((group) => ({
        value: group.id,
        label: `${group.name} · ${formatNumber(group.monitorings_count)} monitorings`,
    })));

    $effect(() => {
        components = statusPage?.components.map((component) => ({
            id: component.id,
            name: component.name,
            description: component.description ?? "",
            sourceType: component.source_type,
            monitoringGroupId: component.monitoring_group?.id ?? "",
            monitoringIds: component.monitorings.map((monitoring) => monitoring.id),
        })) ?? [emptyComponent()];
    });

    function emptyComponent(): FormComponent {
        return { name: "", description: "", sourceType: "manual", monitoringGroupId: "", monitoringIds: [] };
    }

    function errorFor(field: string): string | undefined {
        return errors[field]?.[0];
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
            const response = await requestFirstPartyApi<StatusPage>(action, {
                body: new FormData(event.currentTarget as HTMLFormElement),
                method,
            });
            await onSuccess(response.data);
        } catch (exception) {
            if (exception instanceof FirstPartyApiError) {
                error = exception.message;
                errors = exception.errors;
            } else {
                error = "The status page could not be saved.";
            }
        } finally {
            submitting = false;
        }
    }
</script>

<form class="grid gap-6" onsubmit={submit} novalidate>
    <div class="grid gap-5 sm:grid-cols-2">
        <Field label="Name" error={errorFor("name")} required><Input name="name" value={statusPage?.name ?? ""} required /></Field>
        <Field label="Visibility" hint="Published pages are available at their public status URL."><Input name="is_public" type="hidden" value="0" /><label class="flex min-h-11 items-center gap-3 rounded-md border border-wg-border px-3 text-sm font-bold"><Checkbox name="is_public" value="1" checked={statusPage?.publication.is_public ?? true} /> Publish this status page</label></Field>
    </div>
    <Field label="Description" error={errorFor("description")}><Textarea name="description" value={statusPage?.description ?? ""} /></Field>

    <Field label="Incident communication" hint="Publish neutral investigating and resolved updates when an incident affects a component on this public page."><input name="auto_publish_incident_updates" type="hidden" value="0" /><label class="flex min-h-11 items-center gap-3 rounded-md border border-wg-border px-3 text-sm font-bold"><Checkbox name="auto_publish_incident_updates" value="1" checked={statusPage?.auto_publish_incident_updates ?? false} /> Automatically publish incident updates</label></Field>

    <fieldset class="grid gap-4 border-t border-wg-border pt-6">
        <div class="flex flex-wrap items-end justify-between gap-3"><div><legend class="text-xl font-bold">Components</legend><p class="mt-1 text-sm leading-5 text-wg-text-muted">Each component can show selected monitorings or keep its monitoring-group source.</p></div><Button type="button" variant="secondary" onclick={() => components.push(emptyComponent())}>Add component</Button></div>
        {#each components as component, index (component.id ?? index)}
            <section class="grid gap-4 border-b border-wg-border pb-5 last:border-b-0">
                <Field label="Source"><Select name={`components[${index}][source_type]`} bind:value={component.sourceType} onchange={() => { component.monitoringGroupId = ""; component.monitoringIds = []; }}><option value="manual">Individual monitorings</option><option value="monitoring_group">Monitoring group</option></Select></Field>
                <div class="flex items-center justify-between gap-3"><h3 class="font-bold">Component {index + 1}</h3><Button class="min-h-10 px-3 py-1.5" variant="danger" type="button" aria-label={`Remove component ${index + 1}`} onclick={() => (components = components.filter((_, componentIndex) => componentIndex !== index))}>Remove</Button></div>
                <div class="grid gap-4 sm:grid-cols-2"><Field label="Component name" error={errorFor(`components.${index}.name`)} required><Input name={`components[${index}][name]`} bind:value={component.name} required /></Field>{#if component.sourceType === "monitoring_group"}<Field label="Monitoring group" error={errorFor(`components.${index}.monitoring_group_id`)} required><Select id={`component-${index}-monitoring-group`} options={monitoringGroupOptions} name={`components[${index}][monitoring_group_id]`} bind:value={component.monitoringGroupId} searchable placeholder="Select monitoring group" searchPlaceholder="Search monitoring groups" required /></Field>{:else}<Field label="Monitorings" error={errorFor(`components.${index}.monitoring_ids`)} required><Select id={`component-${index}-monitorings`} options={monitoringOptions} name={`components[${index}][monitoring_ids][]`} multiple searchable placeholder="Select monitorings" searchPlaceholder="Search monitorings" bind:value={component.monitoringIds} required /></Field>{/if}</div>
                <Field label="Component description" error={errorFor(`components.${index}.description`)}><Textarea class="min-h-20" name={`components[${index}][description]`} bind:value={component.description} /></Field>
            </section>
        {/each}
        {#if errorFor("components")}<p class="text-sm font-bold text-wg-danger" role="alert">{errorFor("components")}</p>{/if}
    </fieldset>
    {#if error}<p class="text-sm font-bold text-wg-danger" role="alert">{error}</p>{/if}
    <Button type="submit" loading={submitting}>{statusPage ? "Save status page" : "Create status page"}</Button>
</form>
