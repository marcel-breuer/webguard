<script lang="ts">
    import { requestFirstPartyApi, FirstPartyApiError } from "#lib/api/client.ts";
    import Button from "#lib/components/Button.svelte";
    import Card from "#lib/components/Card.svelte";
    import Checkbox from "#lib/components/Checkbox.svelte";
    import Field from "#lib/components/Field.svelte";
    import Input from "#lib/components/Input.svelte";
    import MutationForm from "#lib/components/MutationForm.svelte";
    import Select from "#lib/components/Select.svelte";
    import { appRoutes } from "#lib/routes.ts";
    import type { NotificationSettings } from "./+page.server";

    interface Props { data: { settings: NotificationSettings }; }
    interface TestResult { channel: string; tested: boolean; }

    const frequencyOptions = ["daily", "weekly", "monthly"] as const;
    let { data }: Props = $props();
    let testingChannel = $state<string | null>(null);
    let testMessage = $state("");
    let testError = $state("");

    function refreshSettings(): void { window.location.reload(); }

    async function testChannel(channel: string): Promise<void> {
        testingChannel = channel;
        testMessage = "";
        testError = "";

        try {
            const result = await requestFirstPartyApi<TestResult>(`/api/profile/notification-settings/${channel}/test`, { method: "POST" });
            testMessage = `${channelLabels[result.data.channel]} test notification sent.`;
        } catch (error) {
            testError = error instanceof FirstPartyApiError ? error.message : "The test notification could not be sent.";
        } finally {
            testingChannel = null;
        }
    }

    const channelLabels: Record<string, string> = {
        slack: "Slack",
        telegram: "Telegram",
        discord: "Discord",
        teams: "Microsoft Teams",
        webhook: "Webhook",
    };
</script>

<svelte:head><title>Notification settings | WebGuard</title></svelte:head>

<main class="mx-auto w-[min(58rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8">
        <a class="text-sm font-bold text-wg-accent no-underline hover:underline" href={appRoutes.profile}>← Profile settings</a>
        <p class="mt-5 mb-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Notifications</p>
        <h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Notification settings</h1>
        <p class="mt-3 max-w-2xl leading-6 text-wg-text-muted">Choose where WebGuard should send alerts. Monitor-specific channel selection remains available on each monitoring.</p>
    </header>

    <MutationForm action="/api/profile/notification-settings" method="PATCH" submitLabel="Save notification settings" successMessage="Notification settings saved. Refreshing…" onSuccess={refreshSettings}>
        <div class="grid gap-6">
            <Card title="Alert channels" description="Enable a channel and add its delivery details. You can send a test without enabling it.">
                <div class="grid gap-4">
                    <section class="rounded-xl border border-wg-border bg-wg-surface-muted p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-base font-bold">Slack</h2><p class="mt-1 text-sm leading-5 text-wg-text-muted">Use a Slack Incoming Webhook URL.</p></div><label class="flex items-center gap-2 text-sm font-bold"><Checkbox name="notification_channels[slack][enabled]" value="1" checked={data.settings.notification_channels.slack.enabled} /> Enabled</label></div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto]"><Field label="Webhook URL"><Input name="notification_channels[slack][webhook_url]" type="url" value={data.settings.notification_channels.slack.webhook_url ?? ""} placeholder="https://hooks.slack.com/services/..." /></Field><Button type="button" variant="secondary" loading={testingChannel === "slack"} onclick={() => testChannel("slack")}>Send test</Button></div>
                    </section>

                    <section class="rounded-xl border border-wg-border bg-wg-surface-muted p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-base font-bold">Telegram</h2><p class="mt-1 text-sm leading-5 text-wg-text-muted">Provide a bot token and the target chat ID.</p></div><label class="flex items-center gap-2 text-sm font-bold"><Checkbox name="notification_channels[telegram][enabled]" value="1" checked={data.settings.notification_channels.telegram.enabled} /> Enabled</label></div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Bot token"><Input name="notification_channels[telegram][bot_token]" type="password" value={data.settings.notification_channels.telegram.bot_token ?? ""} autocomplete="off" /></Field><Field label="Chat ID"><Input name="notification_channels[telegram][chat_id]" value={data.settings.notification_channels.telegram.chat_id ?? ""} /></Field></div>
                        <div class="mt-4"><Button type="button" variant="secondary" loading={testingChannel === "telegram"} onclick={() => testChannel("telegram")}>Send test</Button></div>
                    </section>

                    <section class="rounded-xl border border-wg-border bg-wg-surface-muted p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-base font-bold">Discord</h2><p class="mt-1 text-sm leading-5 text-wg-text-muted">Use a Discord webhook URL.</p></div><label class="flex items-center gap-2 text-sm font-bold"><Checkbox name="notification_channels[discord][enabled]" value="1" checked={data.settings.notification_channels.discord.enabled} /> Enabled</label></div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto]"><Field label="Webhook URL"><Input name="notification_channels[discord][webhook_url]" type="url" value={data.settings.notification_channels.discord.webhook_url ?? ""} placeholder="https://discord.com/api/webhooks/..." /></Field><Button type="button" variant="secondary" loading={testingChannel === "discord"} onclick={() => testChannel("discord")}>Send test</Button></div>
                    </section>

                    <section class="rounded-xl border border-wg-border bg-wg-surface-muted p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-base font-bold">Microsoft Teams</h2><p class="mt-1 text-sm leading-5 text-wg-text-muted">Use a Microsoft Teams incoming webhook URL.</p></div><label class="flex items-center gap-2 text-sm font-bold"><Checkbox name="notification_channels[teams][enabled]" value="1" checked={data.settings.notification_channels.teams.enabled} /> Enabled</label></div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto]"><Field label="Webhook URL"><Input name="notification_channels[teams][webhook_url]" type="url" value={data.settings.notification_channels.teams.webhook_url ?? ""} placeholder="https://..." /></Field><Button type="button" variant="secondary" loading={testingChannel === "teams"} onclick={() => testChannel("teams")}>Send test</Button></div>
                    </section>

                    <section class="rounded-xl border border-wg-border bg-wg-surface-muted p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-base font-bold">Webhook</h2><p class="mt-1 text-sm leading-5 text-wg-text-muted">Send the notification payload to a custom HTTP endpoint.</p></div><label class="flex items-center gap-2 text-sm font-bold"><Checkbox name="notification_channels[webhook][enabled]" value="1" checked={data.settings.notification_channels.webhook.enabled} /> Enabled</label></div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto]"><Field label="Webhook URL"><Input name="notification_channels[webhook][url]" type="url" value={data.settings.notification_channels.webhook.url ?? ""} placeholder="https://example.com/webhook" /></Field><Button type="button" variant="secondary" loading={testingChannel === "webhook"} onclick={() => testChannel("webhook")}>Send test</Button></div>
                    </section>
                </div>
                {#if testMessage}<p class="mt-4 text-sm font-semibold text-green-700 dark:text-green-300" role="status">{testMessage}</p>{/if}
                {#if testError}<p class="mt-4 text-sm font-semibold text-wg-danger" role="alert">{testError}</p>{/if}
            </Card>

            <Card title="Email summaries" description="Choose the cadence for digest and unread-message reminder emails.">
                <div class="grid gap-6 sm:grid-cols-2">
                    <section class="grid content-start gap-4"><label class="flex items-start gap-3 text-sm font-bold"><Checkbox class="mt-1" name="monitoring_digest_enabled" value="1" checked={data.settings.monitoring_digest_enabled} /><span>Enable monitoring digest by email</span></label><Field label="Time window"><Select name="monitoring_digest_frequency" value={data.settings.monitoring_digest_frequency}>{#each frequencyOptions as frequency}<option value={frequency}>{frequency[0].toUpperCase() + frequency.slice(1)}</option>{/each}</Select></Field></section>
                    <section class="grid content-start gap-4"><label class="flex items-start gap-3 text-sm font-bold"><Checkbox class="mt-1" name="unread_notifications_reminder_enabled" value="1" checked={data.settings.unread_notifications_reminder_enabled} /><span>Enable email reminders for unread messages</span></label><Field label="Interval"><Select name="unread_notifications_reminder_frequency" value={data.settings.unread_notifications_reminder_frequency}>{#each frequencyOptions as frequency}<option value={frequency}>{frequency === "weekly" ? "Every 7 days" : frequency[0].toUpperCase() + frequency.slice(1)}</option>{/each}</Select></Field></section>
                </div>
            </Card>
        </div>
    </MutationForm>
</main>
