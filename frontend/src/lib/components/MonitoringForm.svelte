<script lang="ts">
    import { goto } from "$app/navigation";
    import { requestFirstPartyApi, FirstPartyApiError } from "$lib/api/client";
    import type { MonitoringFormOptions, MonitoringMutationResult, MonitoringType } from "$lib/api/monitoring";
    import Button from "$lib/components/Button.svelte";
    import Checkbox from "$lib/components/Checkbox.svelte";
    import Field from "$lib/components/Field.svelte";
    import Input from "$lib/components/Input.svelte";
    import Select, { type SelectOption } from "$lib/components/Select.svelte";
    import Textarea from "$lib/components/Textarea.svelte";

    interface Props {
        options: MonitoringFormOptions;
        action: string;
        method: "POST" | "PATCH";
        presentation?: "default" | "edit-modal" | "first-website";
        onSuccess?: (monitoring: MonitoringMutationResult) => void | Promise<void>;
        onCancel?: () => void;
    }

    let { options, action, method, presentation = "default", onSuccess, onCancel }: Props = $props();
    const initial = initialState();
    const monitoring = initial.monitoring;
    let type = $state<MonitoringType>(initial.type);
    let selectedLocations = $state<string[]>(initial.locations);
    let selectedGroups = $state<string[]>(initial.groups);
    let submitting = $state(false);
    let message = $state("");
    let errors = $state<Record<string, string[]>>({});
    let firstTarget = $state("");
    let advancedOpen = $state(false);

    const httpTypes = $derived(type === "http" || type === "keyword");
    const generatedTarget = $derived(type === "heartbeat" || type === "server_health");
    const locationOptions = $derived<SelectOption[]>(options.locations.map((location) => ({ value: location, label: location })));
    const groupOptions = $derived<SelectOption[]>(options.groups.map((group) => ({ value: group.id, label: group.name })));

    function initialState(): { monitoring: MonitoringFormOptions["monitoring"]; type: MonitoringType; locations: string[]; groups: string[] } {
        const configuredMonitoring = options.monitoring;

        return {
            monitoring: configuredMonitoring,
            type: configuredMonitoring?.type ?? "http",
            locations: configuredMonitoring?.preferred_locations ?? options.locations.slice(0, 1),
            groups: configuredMonitoring?.group_ids ?? [],
        };
    }

    function errorFor(name: string): string | undefined {
        return errors[name]?.[0];
    }

    function defaultWebsiteName(target: string): string {
        try {
            return new URL(target.includes("://") ? target : `https://${target}`).hostname.replace(/^www\./, "") || target;
        } catch {
            return target;
        }
    }

    async function submit(event: SubmitEvent): Promise<void> {
        event.preventDefault();

        if (submitting) {
            return;
        }

        submitting = true;
        message = "";
        errors = {};

        try {
            const form = event.currentTarget as HTMLFormElement;
            const body = new FormData(form);

            if (presentation === "first-website" && !String(body.get("name") ?? "").trim()) {
                body.set("name", defaultWebsiteName(String(body.get("target") ?? "")) || `${type.replaceAll("_", " ")} check`);
            }

            const response = await requestFirstPartyApi<MonitoringMutationResult>(action, {
                body,
                method,
            });

            if (onSuccess) {
                await onSuccess(response.data);
            } else {
                await goto(`/monitorings/${response.data.id}`);
            }
        } catch (error) {
            if (error instanceof FirstPartyApiError) {
                errors = error.errors;
                message = error.message;
                if (presentation === "first-website" && Object.keys(error.errors).some((field) => field !== "target" && field !== "name")) {
                    advancedOpen = true;
                }
            } else {
                message = "The monitoring could not be saved. Please try again.";
            }
        } finally {
            submitting = false;
        }
    }
</script>

{#snippet basicFields()}
    <div class="grid gap-4 sm:grid-cols-2">
        <Field label="Monitoring type" error={errorFor("type")} required>
            {#if monitoring}
                <Input value={type} readonly />
                <Input name="type" type="hidden" value={type} />
            {:else}
                <Select name="type" bind:value={type}>
                    {#each options.types as option}<option value={option}>{option.replaceAll("_", " ")}</option>{/each}
                </Select>
            {/if}
        </Field>
        <Field label="Lifecycle" error={errorFor("status")} required>
            <Select name="status" value={monitoring?.status ?? "active"}>
                <option value="active">Active</option><option value="paused">Paused</option>
            </Select>
        </Field>
    </div>
    <Field label="Name" error={errorFor("name")} required><Input name="name" value={monitoring?.name ?? ""} required /></Field>
    {#if generatedTarget}
        <p class="rounded-xl border border-dashed border-wg-border bg-wg-surface-muted p-3 text-sm text-wg-text-muted">The endpoint is generated securely after this monitoring is saved.</p>
    {:else}
        <Field label="Target" error={errorFor("target")} required><Input name="target" value={monitoring?.target ?? ""} required /></Field>
    {/if}
{/snippet}

{#snippet ownershipFields()}
    {#if monitoring}
        <Field label="Ownership"><Input value={monitoring.ownership.type === "team" ? `Team: ${monitoring.ownership.team_name ?? "Unknown"}` : "Private"} readonly /></Field>
    {:else if options.teams.length > 0}
        <Field label="Owner"><Select name="team_id"><option value="">Private monitoring</option>{#each options.teams as team}<option value={team.id}>Team: {team.name}</option>{/each}</Select></Field>
    {/if}
    <Field label="Monitoring locations" error={errorFor("preferred_locations")}><Select options={locationOptions} name="preferred_locations[]" multiple searchable placeholder="Select monitoring locations" searchPlaceholder="Search monitoring locations" bind:value={selectedLocations} /></Field>
    {#if !monitoring || monitoring.can_assign_groups}
        <Field label="Groups"><Select options={groupOptions} name="group_ids[]" multiple searchable placeholder="Select groups" searchPlaceholder="Search groups" bind:value={selectedGroups} /></Field>
    {/if}
{/snippet}

{#snippet checkFields()}
    {#if type === "port"}
        <Field label="Port" error={errorFor("port")} required><Input name="port" type="number" min="1" max="65535" value={monitoring?.port ?? ""} required /></Field>
    {:else if type === "dns_record"}
        <div class="grid gap-4 sm:grid-cols-2"><Field label="DNS record type" error={errorFor("dns_record_type")} required><Select name="dns_record_type" value={monitoring?.dns_record_type ?? "A"}>{#each ["A", "AAAA", "CNAME", "MX", "TXT", "NS", "SOA", "CAA"] as recordType}<option value={recordType}>{recordType}</option>{/each}</Select></Field><Field label="Expected values" error={errorFor("dns_expected_values")} required><Textarea class="min-h-28" name="dns_expected_values" value={monitoring?.dns_expected_values?.join("\n") ?? ""} required /></Field></div>
    {:else if type === "heartbeat"}
        <div class="grid gap-4 sm:grid-cols-2"><Field label="Expected interval (minutes)" error={errorFor("heartbeat_interval_minutes")} required><Input name="heartbeat_interval_minutes" type="number" min="1" value={monitoring?.heartbeat_interval_minutes ?? 5} required /></Field><Field label="Grace period (minutes)" error={errorFor("heartbeat_grace_minutes")} required><Input name="heartbeat_grace_minutes" type="number" min="0" value={monitoring?.heartbeat_grace_minutes ?? 5} required /></Field></div>
    {:else if type === "server_health"}
        <div class="grid gap-4 sm:grid-cols-2"><Field label="CPU threshold (%)" error={errorFor("server_health_cpu_threshold_percent")} required><Input name="server_health_cpu_threshold_percent" type="number" min="1" max="100" value={monitoring?.server_health_cpu_threshold_percent ?? 90} required /></Field><Field label="RAM threshold (%)" error={errorFor("server_health_ram_threshold_percent")} required><Input name="server_health_ram_threshold_percent" type="number" min="1" max="100" value={monitoring?.server_health_ram_threshold_percent ?? 90} required /></Field><Field label="Storage threshold (%)" error={errorFor("server_health_storage_threshold_percent")} required><Input name="server_health_storage_threshold_percent" type="number" min="1" max="100" value={monitoring?.server_health_storage_threshold_percent ?? 90} required /></Field><Field label="Report interval (minutes)" error={errorFor("server_health_report_interval_minutes")} required><Input name="server_health_report_interval_minutes" type="number" min="1" value={monitoring?.server_health_report_interval_minutes ?? 1} required /></Field><Field label="Grace period (minutes)" error={errorFor("server_health_grace_minutes")} required><Input name="server_health_grace_minutes" type="number" min="0" value={monitoring?.server_health_grace_minutes ?? 5} required /></Field></div>
    {/if}
    {#if httpTypes}
        <div class="grid gap-4 sm:grid-cols-2">
            <Field label="HTTP method" error={errorFor("http_method")}><Select name="http_method" value={monitoring?.http_method ?? "get"}>{#each ["get", "post", "put", "patch", "delete"] as httpMethod}<option value={httpMethod}>{httpMethod.toUpperCase()}</option>{/each}</Select></Field>
            <Field label="Timeout (seconds)" error={errorFor("timeout")} required><Input name="timeout" type="number" min="1" max="60" value={monitoring?.timeout ?? 5} required /></Field>
            <Field label="Expected HTTP statuses" error={errorFor("expected_http_statuses")}><Input name="expected_http_statuses" value={monitoring?.expected_http_statuses ?? "200-299"} /></Field>
            {#if type === "keyword"}<Field label="Required keyword" error={errorFor("keyword")} required><Input name="keyword" value={monitoring?.keyword ?? ""} required /></Field>{/if}
        </div>
        {#if monitoring}<label class="flex items-center gap-2 text-sm text-wg-text-muted"><Checkbox name="clear_auth_password" /> Remove stored HTTP basic-auth password</label>{/if}
    {/if}
{/snippet}

{#snippet notificationFields()}
    <div class="grid gap-4 sm:grid-cols-2"><Field label="Failure confirmation attempts" error={errorFor("failure_confirmation_threshold")} required><Input name="failure_confirmation_threshold" type="number" min="1" max="10" value={monitoring?.failure_confirmation_threshold ?? 2} required /></Field><Field label="SSL warning days" error={errorFor("ssl_expiry_warning_days")} required><Input name="ssl_expiry_warning_days" type="number" min="1" max="365" value={monitoring?.ssl_expiry_warning_days ?? 7} required /></Field></div>
    <label class="flex items-center gap-2 text-sm font-bold"><Checkbox name="notification_on_failure" checked={monitoring?.notification_on_failure ?? true} /> Notify on failure</label>
{/snippet}

<form class={presentation === "edit-modal" ? "grid gap-0" : "grid gap-6"} onsubmit={submit} novalidate>
    {#if presentation === "first-website"}
        <section class="grid gap-4 pb-6">
            {#if generatedTarget}
                <p class="rounded-xl border border-dashed border-wg-border bg-wg-surface-muted p-3 text-sm text-wg-text-muted">The endpoint is generated securely after this monitoring is saved.</p>
            {:else}
                <Field label={type === "http" ? "Website URL" : "Target"} error={errorFor("target")} required>
                    <Input name="target" type={type === "http" || type === "keyword" ? "url" : "text"} bind:value={firstTarget} placeholder={type === "http" ? "https://example.com" : "example.com"} required />
                </Field>
            {/if}
            <p class="-mt-2 text-sm text-wg-text-muted">{type === "http" ? "WebGuard will use the standard HTTP availability check. Your first results appear on the website details page." : "WebGuard will use the selected monitoring type. Your first results appear on the monitoring details page."}</p>
        </section>
        <details class="group border-t border-wg-border" bind:open={advancedOpen}>
            <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-4 py-4 text-base font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus [&::-webkit-details-marker]:hidden"><span>Advanced options</span><span class="text-base text-wg-text-muted transition group-open:rotate-180" aria-hidden="true">⌄</span></summary>
            <div class="grid gap-5 pb-6">
                <section class="grid gap-4">
                    <h3 class="text-lg font-bold">Website details</h3>
                    <Field label="Name" error={errorFor("name")}><Input name="name" placeholder={defaultWebsiteName(firstTarget)} /></Field>
                    <Field label="Monitoring type" error={errorFor("type")}>
                        <Select name="type" bind:value={type}>{#each options.types as option}<option value={option}>{option.replaceAll("_", " ")}</option>{/each}</Select>
                    </Field>
                    <Field label="Lifecycle" error={errorFor("status")}><Select name="status" value="active"><option value="active">Active</option><option value="paused">Paused</option></Select></Field>
                </section>
                <section class="grid gap-4 border-t border-wg-border pt-5">
                    <h3 class="text-lg font-bold">Check settings</h3>
                    {@render checkFields()}
                </section>
                <section class="grid gap-4 border-t border-wg-border pt-5">
                    <h3 class="text-lg font-bold">Assignment and alerts</h3>
                    {@render ownershipFields()}
                    {@render notificationFields()}
                </section>
            </div>
        </details>
    {:else if presentation === "edit-modal"}
        <section class="grid gap-4 border-b border-wg-border pb-6">
            {#if monitoring}<p class="text-sm font-bold text-wg-text-muted">Ownership <span class="ml-2 rounded-full bg-violet-50 px-2.5 py-1 text-xs font-extrabold text-wg-accent dark:bg-violet-950/50">{monitoring.ownership.type === "team" ? "Team" : "Private"}</span></p>{/if}
            <h3 class="text-xl font-bold">Basics</h3>
            {@render basicFields()}
        </section>

        <details class="group border-b border-wg-border">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-xl font-bold [&::-webkit-details-marker]:hidden"><span>Ownership and groups</span><span class="text-base text-wg-text-muted transition group-open:rotate-180">⌄</span></summary>
            <div class="grid gap-4 pb-6">{@render ownershipFields()}</div>
        </details>
        <details class="group border-b border-wg-border">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-xl font-bold [&::-webkit-details-marker]:hidden"><span>Check configuration</span><span class="text-base text-wg-text-muted transition group-open:rotate-180">⌄</span></summary>
            <div class="grid gap-4 pb-6">{@render checkFields()}</div>
        </details>
        <details class="group border-b border-wg-border">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-xl font-bold [&::-webkit-details-marker]:hidden"><span>Public display</span><span class="text-base text-wg-text-muted transition group-open:rotate-180">⌄</span></summary>
            <div class="pb-6 text-sm leading-6 text-wg-text-muted">Publish this monitoring on a status page from <a class="font-bold text-wg-accent underline underline-offset-2" href="/status-pages">Status pages</a>.</div>
        </details>
        <details class="group border-b border-wg-border">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-xl font-bold [&::-webkit-details-marker]:hidden"><span>Notifications</span><span class="text-base text-wg-text-muted transition group-open:rotate-180">⌄</span></summary>
            <div class="grid gap-4 pb-6">{@render notificationFields()}</div>
        </details>
        <details class="group border-b border-wg-border">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-xl font-bold [&::-webkit-details-marker]:hidden"><span>Operations</span><span class="text-base text-wg-text-muted transition group-open:rotate-180">⌄</span></summary>
            <div class="pb-6 text-sm leading-6 text-wg-text-muted">Operational actions such as pausing or deleting this monitoring remain available from the monitoring details.</div>
        </details>
    {:else}
        <section class="grid gap-4">
            <h2 class="text-lg font-bold">Basic configuration</h2>
            {@render basicFields()}
        </section>
        <section class="grid gap-4 border-t border-wg-border pt-6">
            <h2 class="text-lg font-bold">Check settings</h2>
            {@render checkFields()}
        </section>
        <section class="grid gap-4 border-t border-wg-border pt-6">
            <h2 class="text-lg font-bold">Assignment and alerts</h2>
            {@render ownershipFields()}
            {@render notificationFields()}
        </section>
    {/if}

    {#if message}<p class="text-sm font-bold text-wg-danger" role="alert">{message}</p>{/if}
    <div class={`flex flex-wrap justify-end gap-3 ${presentation === "edit-modal" ? "sticky bottom-0 z-10 -mx-4 -mb-4 border-t border-wg-border bg-wg-surface px-4 py-4 sm:-mx-6 sm:-mb-6 sm:px-6" : ""}`}>
        {#if onCancel}
            <Button variant="secondary" type="button" onclick={onCancel}>Cancel</Button>
        {:else}
            <a class="inline-flex min-h-11 items-center justify-center rounded-md border border-wg-border bg-wg-surface px-4 py-2.5 text-sm font-semibold tracking-[0.035em] text-wg-text no-underline transition hover:border-wg-focus hover:bg-wg-surface-muted" href={monitoring ? `/monitorings/${monitoring.id}` : "/monitorings"}>Cancel</a>
        {/if}
        <Button type="submit" loading={submitting}>{monitoring ? (presentation === "edit-modal" ? "Update" : "Save monitoring") : presentation === "first-website" ? "Add website" : "Create monitoring"}</Button>
    </div>
</form>
