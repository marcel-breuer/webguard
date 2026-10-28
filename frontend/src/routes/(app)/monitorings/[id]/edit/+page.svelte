<script lang="ts">
    import MonitoringForm from "#lib/components/MonitoringForm.svelte";
    import MonitoringOperationsPanel from "#lib/components/MonitoringOperationsPanel.svelte";
    import type { MonitoringFormOptions } from "#lib/api/monitoring.ts";

    interface Props { data: { form: MonitoringFormOptions; preferences: NotificationPreferences }; }
    let { data }: Props = $props();

    interface NotificationPreferences { effective: { notification_on_failure: boolean; notification_channels: string[]; ssl_expiry_warning_days: number; }; permitted_channels: string[]; can_update: boolean; }
</script>

<svelte:head><title>Edit monitoring | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(52rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8"><p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Operations</p><h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Edit monitoring</h1><p class="mt-3 leading-6 text-wg-text-muted">Update monitoring configuration and alert behavior.</p></header>
    <MonitoringForm options={data.form} action={`/api/monitorings/${data.form.monitoring?.id}`} method="PATCH" />
    <MonitoringOperationsPanel options={data.form} preferences={data.preferences} />
</main>
