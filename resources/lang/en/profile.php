<?php

declare(strict_types=1);

return [
    'title' => 'Profile',
    'navigation' => [
        'heading' => 'Account settings',
        'account' => 'Account and appearance',
        'security' => 'Security',
        'api' => 'API and documentation',
        'danger_zone' => 'Delete account',
    ],
    'delete_account' => [
        'heading' => 'Delete Account',
        'description' => 'Once your account is deleted, all of its resources and data will be permanently deleted.',
        'confirmation_question' => 'Are you sure you want to delete your account?',
        'confirmation_warning' => 'Please enter your password to confirm this action.',
    ],
    'information' => [
        'title' => 'Profile Information',
        'heading' => 'Profile Information',
        'description' => "Update your account's profile information and email address.",
        'text' => "Update your account's profile information and email address.",
        'email_unverified' => 'Your email address is unverified.',
        'send_verification_email' => 'Click here to re-send the verification email',
        'verification_email_sent' => 'A new verification email has been sent to your email address.',
    ],
    'sections' => [
        'account' => 'Account',
        'preferences' => 'Appearance',
    ],
    'theme' => [
        'heading' => 'Theme',
        'description' => 'Select your preferred theme.',
    ],
    'notification_settings' => [
        'heading' => 'Notification Settings',
        'description' => 'Configure your notification channels. Choose which channels a monitoring uses in that monitoring.',
        'channels_heading' => 'Channels',
        'enabled' => 'Enabled',
        'hint_banner' => 'Configure at least one channel to continue receiving incident, SSL, and domain expiry alerts.',
        'digest' => [
            'heading' => 'Monitoring digest',
            'description' => 'Receive an email summary across all of your active monitorings.',
            'enabled' => 'Enable monitoring digest by email',
            'frequency' => 'Time window',
            'frequencies' => [
                'daily' => 'Daily',
                'weekly' => 'Weekly',
                'monthly' => 'Monthly',
            ],
        ],
        'unread_reminder' => [
            'heading' => 'Unread message reminder',
            'description' => 'Receive an email when your notification overview still contains unread messages.',
            'enabled' => 'Enable email reminders for unread messages',
            'frequency' => 'Interval',
            'frequencies' => [
                'daily' => 'Daily',
                'weekly' => 'Every 7 days',
                'monthly' => 'Monthly',
            ],
        ],
        'test' => [
            'action' => 'Send test',
            'messages' => [
                'sent' => ':channel test notification sent successfully.',
                'failed' => ':channel test notification could not be sent. Check the saved channel configuration and try again.',
            ],
            'payload' => [
                'title' => 'WebGuard test notification',
                'message' => 'Your :channel notification channel is configured correctly.',
            ],
        ],
        'events' => [
            'incident' => 'Incident',
            'recovery' => 'Recovery',
            'ssl_expiring' => 'SSL expiring',
            'ssl_expired' => 'SSL expired',
            'domain_expiring' => 'Domain expiring',
            'domain_expired' => 'Domain expired',
        ],
        'expiry_warning_days' => [
            'heading' => 'Expiry warning windows',
            'help' => 'Choose when SSL certificates and domains should trigger expiry warnings.',
            'option' => '{1} :days day before expiry|[2,*] :days days before expiry',
        ],
        'fields' => [
            'telegram_bot_token' => 'Telegram Bot Token',
            'telegram_chat_id' => 'Telegram Chat ID',
            'slack_webhook_url' => 'Slack Webhook URL',
            'discord_webhook_url' => 'Discord Webhook URL',
            'teams_webhook_url' => 'Microsoft Teams Webhook URL',
            'webhook_url' => 'Webhook URL',
        ],
        'channels' => [
            'slack' => [
                'title' => 'Slack',
                'help' => 'Use a Slack Incoming Webhook URL.',
            ],
            'telegram' => [
                'title' => 'Telegram',
                'help' => 'Provide your bot token and target chat ID.',
            ],
            'discord' => [
                'title' => 'Discord',
                'help' => 'Use a Discord webhook URL.',
            ],
            'teams' => [
                'title' => 'Microsoft Teams',
                'help' => 'Use a Microsoft Teams incoming webhook URL.',
            ],
            'webhook' => [
                'title' => 'Webhook',
                'help' => 'Send the notification payload to a custom HTTP endpoint.',
            ],
            'mobile_push' => [
                'title' => 'Mobile Push',
                'help' => 'Send alerts to registered iOS and Android devices.',
            ],
            'email' => [
                'title' => 'Email',
                'help' => 'Send alerts to your verified account email address.',
                'unverified' => 'Verify your account email address before enabling email alerts.',
            ],
        ],
    ],
    'update_password' => [
        'heading' => 'Update Password',
        'description' => 'Ensure your account is using a long, random password to stay secure.',
    ],
    'form' => [
        'current_password' => 'Current Password',
        'new_password' => 'New Password',
        'confirm_new_password' => 'Confirm New Password',
        'saved' => 'Password updated successfully.',
    ],
    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'language' => 'Language',
        'theme' => 'Theme',
        'theme_light' => 'Light',
        'theme_dark' => 'Dark',
        'theme_system' => 'System',
        'password' => 'Password',
        'confirm_password' => 'Confirm Password',
        'current_password' => 'Current Password',
        'new_password' => 'New Password',
        'confirm_new_password' => 'Confirm New Password',
    ],
    'actions' => [
        'update_password' => 'Update Password',
        'update_profile' => 'Update Profile',
        'open_full_page' => 'Open full-page editor',
        'delete_account' => 'Delete Account',
        'send_verification_email' => 'Click here to re-send the verification email',
    ],
    'messages' => [
        'email_verified' => 'Your email address is verified.',
        'profile_information_saved' => 'Profile information saved successfully.',
        'profile_updated' => 'Profile updated successfully.',
    ],
];
