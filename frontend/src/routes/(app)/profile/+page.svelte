<script lang="ts">
    import { untrack } from "svelte";
    import Button from "$lib/components/Button.svelte";
    import Card from "$lib/components/Card.svelte";
    import Checkbox from "$lib/components/Checkbox.svelte";
    import Dialog from "$lib/components/Dialog.svelte";
    import Field from "$lib/components/Field.svelte";
    import Input from "$lib/components/Input.svelte";
    import MutationForm from "$lib/components/MutationForm.svelte";
    import Select from "$lib/components/Select.svelte";
    import { appRoutes } from "$lib/routes";
    import { formatDateTime } from "$lib/i18n/format";
    import type { FirstPartySession } from "$lib/api/models";

    interface ApiKeySummary { id: number; name: string; abilities: string[]; last_used_at: string | null; revoked_at: string | null; }
    interface CreatedApiKey { api_key: ApiKeySummary; token: string; }
    interface Props { data: { session: FirstPartySession; apiKeys: ApiKeySummary[] }; }

    let { data }: Props = $props();
    let preferredLocale = $state(untrack(() => data.session.user.locale));
    let preferredFormatLocale = $state(untrack(() => data.session.user.format_locale ?? ""));
    let apiKeys = $state<ApiKeySummary[]>([]);
    let createOpen = $state(false);
    let revokeTarget = $state<ApiKeySummary | null>(null);
    let deleteOpen = $state(false);
    let revealedToken = $state<string | null>(null);

    $effect(() => {
        apiKeys = data.apiKeys;
    });

    function refreshProfile(): void { window.location.reload(); }
    function revealCreatedApiKey(created: CreatedApiKey): void { apiKeys = [created.api_key, ...apiKeys]; revealedToken = created.token; createOpen = false; }
    function markApiKeyRevoked(apiKey: ApiKeySummary): void { apiKeys = apiKeys.map((candidate) => candidate.id === apiKey.id ? apiKey : candidate); revokeTarget = null; }
    function finishDeletion(): void { window.location.assign("/"); }
    function dateTime(value: string | null): string { return formatDateTime(value, "Never used"); }
</script>

<svelte:head><title>Profile | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(52rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8"><p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Account</p><h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Profile settings</h1><span class="mt-3 block leading-6 text-wg-text-muted">Manage your account details, credentials, and security settings.</span></header>

    <div class="grid gap-6">
        <Card title="Account information" description="Changing your email requires verification again.">
            <MutationForm action="/api/profile" method="PATCH" submitLabel="Save changes" successMessage="Profile saved. Refreshing your session…" onSuccess={refreshProfile}>
                <Field label="Name" required><Input name="name" autocomplete="name" value={data.session.user.name} /></Field>
                <Field label="Email" required><Input name="email" type="email" autocomplete="email" value={data.session.user.email} /></Field>
            </MutationForm>
        </Card>

        <Card title="Language and regional formats" description="Choose the language and regional formats used throughout WebGuard.">
            <MutationForm action="/api/profile/preferences" method="PATCH" submitLabel="Save preferences" successMessage="Preferences saved. Refreshing your session…" onSuccess={refreshProfile}>
                <Field label="Language" hint="Choose the language used throughout the interface.">
                    <Select name="locale" bind:value={preferredLocale} required>
                        <option value="en">English</option>
                        <option value="de">Deutsch</option>
                    </Select>
                </Field>
                <Field label="Number, date and time format" hint="Choose how numbers, currencies, dates and times are displayed. This can differ from the interface language.">
                    <Select name="format_locale" bind:value={preferredFormatLocale}>
                        <option value="">Use language default</option>
                        <option value="de-DE">German (Germany) · 1.234,56</option>
                        <option value="en-GB">English (United Kingdom) · 1,234.56</option>
                        <option value="en-US">English (United States) · 1,234.56</option>
                    </Select>
                </Field>
            </MutationForm>
        </Card>

        <Card title="Password" description="Use your current password to confirm this security-sensitive change.">
            <MutationForm action="/api/profile/password" method="PUT" submitLabel="Update password" successMessage="Password updated.">
                <Field label="Current password" required><Input name="current_password" type="password" autocomplete="current-password" /></Field>
                <Field label="New password" required><Input name="password" type="password" autocomplete="new-password" /></Field>
                <Field label="Confirm new password" required><Input name="password_confirmation" type="password" autocomplete="new-password" /></Field>
            </MutationForm>
        </Card>

        <Card title="API keys" description="Create scoped credentials for supported WebGuard integrations.">
            {#snippet actions()}<Button onclick={() => (createOpen = true)}>Create API key</Button>{/snippet}
            {#if apiKeys.length === 0}<p class="m-0 text-sm leading-6 text-wg-text-muted">No API keys have been created.</p>{:else}<div class="divide-y divide-wg-border rounded-xl border border-wg-border">{#each apiKeys as apiKey (apiKey.id)}<article class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="m-0 font-bold">{apiKey.name}</p><p class="mt-1 text-sm text-wg-text-muted">{apiKey.abilities.join(", ")} · {dateTime(apiKey.last_used_at)}</p></div>{#if apiKey.revoked_at}<span class="rounded-full bg-wg-surface-muted px-3 py-1 text-xs font-bold text-wg-text-muted">Revoked</span>{:else}<Button variant="danger" onclick={() => (revokeTarget = apiKey)}>Revoke</Button>{/if}</article>{/each}</div>{/if}
        </Card>

        <Card title="Notification settings" description="Configure alert channels, monitoring digests, and unread-message reminders.">
            {#snippet actions()}<a class="inline-flex min-h-11 items-center justify-center rounded-xl border border-wg-border bg-wg-surface px-4 py-2.5 text-sm font-bold tracking-[0.035em] text-wg-text no-underline transition-[background-color,border-color,color] duration-150 hover:border-wg-focus hover:bg-wg-surface-muted" href={appRoutes.profileNotifications}>Configure notifications</a>{/snippet}
            <p class="m-0 text-sm leading-6 text-wg-text-muted">Send a test after changing a channel to verify its delivery configuration.</p>
        </Card>

        <Card title="Delete account" description="Your login is disabled immediately and your account is removed through the existing deletion process.">
            {#snippet actions()}<Button variant="danger" onclick={() => (deleteOpen = true)}>Delete account</Button>{/snippet}
            <p class="m-0 text-sm leading-6 text-wg-text-muted">This action cannot be undone.</p>
        </Card>
    </div>
</main>

<Dialog bind:open={createOpen} title="Create API key" description="Copy the key immediately. It will only be shown once.">
    <MutationForm action="/api/profile/api-keys" submitLabel="Create API key" successMessage="API key created." onSuccess={revealCreatedApiKey}>
        <Field label="Name" required><Input name="name" maxlength={100} autocomplete="off" /></Field>
        <fieldset class="grid gap-3"><legend class="text-sm font-bold text-wg-text">Permissions</legend><label class="flex items-start gap-3 text-sm"><Checkbox class="mt-1" name="abilities[]" value="analytics:read" /><span><strong>Read analytics</strong><br /><span class="text-wg-text-muted">Read monitoring analytics and availability data.</span></span></label><label class="flex items-start gap-3 text-sm"><Checkbox class="mt-1" name="abilities[]" value="server-health:write" /><span><strong>Write server health</strong><br /><span class="text-wg-text-muted">Submit server health reports from an approved integration.</span></span></label></fieldset>
    </MutationForm>
</Dialog>

<Dialog open={revokeTarget !== null} onclose={() => (revokeTarget = null)} title="Revoke API key" description="The key will stop working immediately.">{#if revokeTarget}<MutationForm action={`/api/profile/api-keys/${revokeTarget.id}`} method="DELETE" submitLabel="Revoke API key" successMessage="API key revoked." onSuccess={markApiKeyRevoked}><p class="m-0 text-sm text-wg-text-muted">Revoke <strong>{revokeTarget.name}</strong>?</p></MutationForm>{/if}</Dialog>
<Dialog bind:open={deleteOpen} title="Delete account" description="Confirm your password to permanently schedule the deletion of your account."><MutationForm action="/api/profile/account" method="DELETE" submitLabel="Delete account" successMessage="Account deletion scheduled." onSuccess={finishDeletion}><Field label="Current password" required><Input name="password" type="password" autocomplete="current-password" /></Field></MutationForm></Dialog>
<Dialog open={revealedToken !== null} onclose={() => (revealedToken = null)} title="Copy your API key" description="Store this value securely. It cannot be recovered after this dialog is closed.">{#if revealedToken}<p class="m-0 break-all rounded-xl border border-wg-border bg-wg-surface-muted p-4 font-mono text-sm" aria-live="polite">{revealedToken}</p>{/if}</Dialog>
