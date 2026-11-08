const notificationChannelLabels: Record<string, string> = {
    email: "Email",
    slack: "Slack",
    telegram: "Telegram",
    discord: "Discord",
    teams: "Microsoft Teams",
    webhook: "Webhook",
    mobile_push: "Mobile Push",
};

export function notificationChannelLabel(channel: string): string {
    return notificationChannelLabels[channel] ?? channel;
}
