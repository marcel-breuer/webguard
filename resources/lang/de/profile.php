<?php

declare(strict_types=1);

return [
    'title' => 'Profil',
    'navigation' => [
        'heading' => 'Kontoeinstellungen',
        'account' => 'Konto und Darstellung',
        'security' => 'Sicherheit',
        'api' => 'API und Dokumentation',
        'danger_zone' => 'Konto löschen',
    ],
    'delete_account' => [
        'heading' => 'Konto löschen',
        'description' => 'Sobald Ihr Konto gelöscht wurde, werden alle zugehörigen Ressourcen und Daten dauerhaft gelöscht.',
        'confirmation_question' => 'Sind Sie sicher, dass Sie Ihr Konto löschen möchten?',
        'confirmation_warning' => 'Bitte geben Sie Ihr Passwort ein, um diese Aktion zu bestätigen.',
    ],
    'information' => [
        'title' => 'Profilinformationen',
        'heading' => 'Profilinformationen',
        'description' => 'Aktualisieren Sie die Profilinformationen und E-Mail-Adresse Ihres Kontos.',
        'text' => 'Aktualisieren Sie die Profilinformationen und E-Mail-Adresse Ihres Kontos.',
        'email_unverified' => 'Ihre E-Mail-Adresse ist nicht bestätigt.',
        'send_verification_email' => 'Klicken Sie hier, um die Bestätigungs-E-Mail erneut zu senden',
        'verification_email_sent' => 'Eine neue Bestätigungs-E-Mail wurde an Ihre E-Mail-Adresse gesendet.',
    ],
    'sections' => [
        'account' => 'Konto',
        'preferences' => 'Darstellung',
    ],
    'theme' => [
        'heading' => 'Design',
        'description' => 'Wählen Sie Ihr bevorzugtes Design.',
    ],
    'notification_settings' => [
        'heading' => 'Benachrichtigungseinstellungen',
        'description' => 'Konfigurieren Sie Ihre Benachrichtigungskanäle. Welche Kanäle eine Überwachung nutzt, legen Sie in der jeweiligen Überwachung fest.',
        'channels_heading' => 'Kanäle',
        'enabled' => 'Aktiviert',
        'hint_banner' => 'Konfigurieren Sie mindestens einen Kanal, um weiterhin Incident-, SSL- und Domain-Ablaufbenachrichtigungen zu erhalten.',
        'digest' => [
            'heading' => 'Monitoring-Zusammenfassung',
            'description' => 'Erhalten Sie eine E-Mail-Zusammenfassung über alle Ihre aktiven Überwachungen.',
            'enabled' => 'Monitoring-Zusammenfassung per E-Mail aktivieren',
            'frequency' => 'Zeitraum',
            'frequencies' => [
                'daily' => 'Täglich',
                'weekly' => 'Jede Woche',
                'monthly' => 'Jeden Monat',
            ],
        ],
        'unread_reminder' => [
            'heading' => 'Erinnerung bei ungelesenen Nachrichten',
            'description' => 'Erhalten Sie eine E-Mail, wenn in Ihrer Benachrichtigungsübersicht noch ungelesene Nachrichten vorhanden sind.',
            'enabled' => 'E-Mail-Erinnerung für ungelesene Nachrichten aktivieren',
            'frequency' => 'Intervall',
            'frequencies' => [
                'daily' => 'Täglich',
                'weekly' => 'Alle 7 Tage',
                'monthly' => 'Jeden Monat',
            ],
        ],
        'test' => [
            'action' => 'Test senden',
            'messages' => [
                'sent' => ':channel-Testbenachrichtigung erfolgreich gesendet.',
                'failed' => ':channel-Testbenachrichtigung konnte nicht gesendet werden. Prüfen Sie die gespeicherte Kanalkonfiguration und versuchen Sie es erneut.',
            ],
            'payload' => [
                'title' => 'WebGuard-Testbenachrichtigung',
                'message' => 'Ihr :channel-Benachrichtigungskanal ist korrekt konfiguriert.',
            ],
        ],
        'events' => [
            'incident' => 'Incident',
            'recovery' => 'Wiederherstellung',
            'ssl_expiring' => 'SSL läuft bald ab',
            'ssl_expired' => 'SSL abgelaufen',
            'domain_expiring' => 'Domain läuft bald ab',
            'domain_expired' => 'Domain abgelaufen',
        ],
        'expiry_warning_days' => [
            'heading' => 'Ablauf-Warnfenster',
            'help' => 'Wählen Sie, wann SSL-Zertifikate und Domains Ablaufwarnungen auslösen sollen.',
            'option' => '{1} :days Tag vor Ablauf|[2,*] :days Tage vor Ablauf',
        ],
        'fields' => [
            'telegram_bot_token' => 'Telegram-Bot-Token',
            'telegram_chat_id' => 'Telegram-Chat-ID',
            'slack_webhook_url' => 'Slack-Webhook-URL',
            'discord_webhook_url' => 'Discord-Webhook-URL',
            'teams_webhook_url' => 'Microsoft-Teams-Webhook-URL',
            'webhook_url' => 'Webhook-URL',
        ],
        'channels' => [
            'slack' => [
                'title' => 'Slack',
                'help' => 'Verwenden Sie eine Slack-Incoming-Webhook-URL.',
            ],
            'telegram' => [
                'title' => 'Telegram',
                'help' => 'Geben Sie den Bot-Token und die Ziel-Chat-ID an.',
            ],
            'discord' => [
                'title' => 'Discord',
                'help' => 'Verwenden Sie eine Discord-Webhook-URL.',
            ],
            'teams' => [
                'title' => 'Microsoft Teams',
                'help' => 'Verwenden Sie eine Microsoft-Teams-Incoming-Webhook-URL.',
            ],
            'webhook' => [
                'title' => 'Webhook',
                'help' => 'Sendet die Payload an einen eigenen HTTP-Endpunkt.',
            ],
            'mobile_push' => [
                'title' => 'Mobile Push',
                'help' => 'Sendet Alarme an registrierte iOS- und Android-Geräte.',
            ],
            'email' => [
                'title' => 'E-Mail',
                'help' => 'Sendet Alarme an Ihre bestätigte Konto-E-Mail-Adresse.',
                'unverified' => 'Bestätigen Sie Ihre Konto-E-Mail-Adresse, bevor Sie E-Mail-Alarme aktivieren.',
            ],
        ],
    ],
    'update_password' => [
        'heading' => 'Passwort aktualisieren',
        'description' => 'Stellen Sie sicher, dass Ihr Konto ein langes, zufälliges Passwort verwendet, um sicher zu bleiben.',
    ],
    'form' => [
        'current_password' => 'Aktuelles Passwort',
        'new_password' => 'Neues Passwort',
        'confirm_new_password' => 'Neues Passwort bestätigen',
        'saved' => 'Passwort erfolgreich aktualisiert.',
    ],
    'fields' => [
        'name' => 'Name',
        'email' => 'E-Mail',
        'language' => 'Sprache',
        'theme' => 'Design',
        'theme_light' => 'Hell',
        'theme_dark' => 'Dunkel',
        'theme_system' => 'System',
        'password' => 'Passwort',
        'confirm_password' => 'Passwort bestätigen',
        'current_password' => 'Aktuelles Passwort',
        'new_password' => 'Neues Passwort',
        'confirm_new_password' => 'Neues Passwort bestätigen',
    ],
    'actions' => [
        'update_password' => 'Passwort aktualisieren',
        'update_profile' => 'Profil aktualisieren',
        'open_full_page' => 'Editor als vollständige Seite öffnen',
        'delete_account' => 'Konto löschen',
        'send_verification_email' => 'Klicken Sie hier, um die Bestätigungs-E-Mail erneut zu senden',
    ],
    'messages' => [
        'email_verified' => 'Ihre E-Mail-Adresse ist bestätigt.',
        'profile_information_saved' => 'Profilinformationen erfolgreich gespeichert.',
        'profile_updated' => 'Profil erfolgreich aktualisiert.',
    ],
];
