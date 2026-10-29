<script lang="ts">
    import Button from "#lib/components/Button.svelte";
    import Checkbox from "#lib/components/Checkbox.svelte";
    import Dialog from "#lib/components/Dialog.svelte";
    import Field from "#lib/components/Field.svelte";
    import Input from "#lib/components/Input.svelte";
    import AppShell from "#lib/components/AppShell.svelte";
    import Card from "#lib/components/Card.svelte";
    import DataTable from "#lib/components/DataTable.svelte";
    import EmptyState from "#lib/components/EmptyState.svelte";
    import type { FirstPartySession } from "#lib/api/models.ts";
    import LoadingState from "#lib/components/LoadingState.svelte";
    import Pagination from "#lib/components/Pagination.svelte";
    import StatusBadge from "#lib/components/StatusBadge.svelte";
    import Select from "#lib/components/Select.svelte";
    import Textarea from "#lib/components/Textarea.svelte";
    import ToastRegion from "#lib/components/ToastRegion.svelte";

    let dialogOpen = $state(false);
    let toastItems = $state([{ id: "preview-saved", tone: "success" as const, message: "Monitoring preferences saved." }]);

    const previewSession: FirstPartySession = {
        user: {
            id: "preview-user",
            name: "Marcel Breuer",
            email: "marcel@example.test",
            role: "admin",
            locale: "en",
            format_locale: null,
            theme: "system",
            email_verified_at: "2026-01-01T00:00:00+00:00",
            is_verified: true,
            onboarding: {
                notification_tested_at: null,
                completed_at: null,
                dismissed_at: null,
            },
        },
        teams: [],
        csrf_endpoint: "/sanctum/csrf-cookie",
    };
</script>

<svelte:head>
    <title>WebGuard UI preview</title>
</svelte:head>

<AppShell session={previewSession} currentPath="/dashboard">
<div class="mx-auto w-[min(70rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-10 max-w-2xl">
        <p class="mb-2 text-[0.8125rem] font-bold tracking-[0.08em] text-wg-text-muted uppercase">Development-only component preview</p>
        <h1 class="m-0 text-[clamp(2rem,6vw,3.5rem)] leading-[1.05]">WebGuard interface primitives</h1>
        <span class="mt-4 block text-base leading-[1.6] text-wg-text-muted">Dark-aware, keyboard-accessible and ready for the SvelteKit workspace.</span>
    </header>

    <section aria-labelledby="buttons-heading" class="mt-5 rounded-2xl border border-wg-border bg-wg-surface p-[1.125rem] shadow-wg-surface sm:p-6">
        <h2 id="buttons-heading" class="mb-4 mt-0 text-base">Actions</h2>
        <div class="flex flex-wrap gap-3">
            <Button>Save changes</Button>
            <Button variant="secondary">Cancel</Button>
            <Button variant="danger">Delete monitoring</Button>
            <Button variant="quiet">View details</Button>
        </div>
    </section>

    <section aria-labelledby="form-heading" class="mt-5 rounded-2xl border border-wg-border bg-wg-surface p-[1.125rem] shadow-wg-surface sm:p-6">
        <h2 id="form-heading" class="mb-4 mt-0 text-base">Fields</h2>
        <div class="grid gap-4 md:grid-cols-2">
            <Field label="Monitoring name" required hint="Visible to your team.">
                <Input name="name" value="API gateway" />
            </Field>
            <Field label="Target URL" error="Enter a publicly reachable URL.">
                <Input class="border-wg-danger" name="url" aria-invalid="true" value="localhost" />
            </Field>
            <Field label="Monitoring type" hint="Use the same native control contract as the management forms.">
                <Select name="type"><option value="http">HTTP</option><option value="keyword">Keyword</option></Select>
            </Field>
            <Field label="Description">
                <Textarea name="description" value="API availability checks for the public gateway." />
            </Field>
            <label class="flex items-center gap-3 rounded-md border border-wg-border px-3 py-2 text-sm font-bold">
                <Checkbox name="notifications" checked /> Notify on failure
            </label>
        </div>
    </section>

    <section aria-labelledby="dialog-heading" class="mt-5 rounded-2xl border border-wg-border bg-wg-surface p-[1.125rem] shadow-wg-surface sm:p-6">
        <h2 id="dialog-heading" class="mb-4 mt-0 text-base">Dialog</h2>
        <Button onclick={() => (dialogOpen = true)}>Open confirmation</Button>
    </section>

    <Card title="Monitoring overview" description="Shared content patterns used by migrated feature pages.">
        {#snippet actions()}<StatusBadge tone="healthy" label="Operational" />{/snippet}
        <DataTable caption="Recent monitorings">
            <thead><tr><th>Name</th><th>Status</th></tr></thead>
            <tbody><tr><td>API gateway</td><td><StatusBadge tone="healthy" label="Up" /></td></tr></tbody>
        </DataTable>
        <div class="mt-4"><Pagination page={2} pages={4} href={(page) => `?page=${page}`} /></div>
    </Card>

    <section aria-label="Feedback states" class="mt-5 grid gap-5 rounded-2xl border border-wg-border bg-wg-surface p-[1.125rem] shadow-wg-surface sm:p-6">
        <LoadingState label="Loading monitoring results" />
        <EmptyState title="No maintenance windows" description="Planned maintenance will appear here once it is scheduled." />
    </section>
</div>

<Dialog bind:open={dialogOpen} title="Discard unsaved changes?" description="Your edits have not been saved yet.">
    <div class="flex flex-wrap gap-3">
        <Button variant="secondary" onclick={() => (dialogOpen = false)}>Keep editing</Button>
        <Button variant="danger" onclick={() => (dialogOpen = false)}>Discard changes</Button>
    </div>
</Dialog>
</AppShell>

<ToastRegion items={toastItems} onDismiss={(id) => (toastItems = toastItems.filter((item) => item.id !== id))} />
