<?php

declare(strict_types=1);

return [
    'title' => 'Benachrichtigungen',
    'status_change_notifications' => 'Statusänderung',
    'status_board' => [
        'heading' => 'Status-Board',
    ],
    'ssl_expiry_notifications' => 'SSL-Ablauf',
    'domain_expiry_notifications' => 'Domain-Ablauf',
    'delivery_history' => [
        'heading' => 'Zustellverlauf',
    ],
    'overview' => [
        'eyebrow' => 'Operations-Inbox',
        'description' => 'Prüfen Sie Monitor-Vorfälle, Ablauf-Risiken und Kanalzustellungen in einer fokussierten Kommandozentrale.',
        'workflow_label' => 'Übersicht des Benachrichtigungs-Workflows',
        'workflow' => [
            'triage' => [
                'label' => 'Triage',
                'title' => 'Statusänderungen',
                'description' => 'Wiederherstellungen und Vorfälle pro Monitor bündeln, damit der aktuelle Zustand sofort handlungsfähig ist.',
            ],
            'expiry' => [
                'label' => 'Risiko',
                'title' => 'Zertifikat- und Domain-Ablauf',
                'description' => 'Sicherheits- und Besitzfristen sichtbar halten, bevor daraus Ausfälle entstehen.',
            ],
            'audit' => [
                'label' => 'Audit',
                'title' => 'Kanalzustellung',
                'description' => 'Nachvollziehen, ob Slack-, Telegram-, Discord-, Microsoft-Teams- und Webhook-Benachrichtigungen ihr Ziel erreicht haben.',
            ],
        ],
    ],
    'sections' => [
        'ssl_expiry' => [
            'description' => 'Zertifikatswarnungen, die Erneuerung oder Prüfung benötigen.',
        ],
        'domain_expiry' => [
            'description' => 'Domain-Fristen und Ablaufwarnungen für überwachte Ziele.',
        ],
        'status_change' => [
            'description' => 'Aktueller Vorfall- und Wiederherstellungsstatus pro Monitor, konsolidiert für schnellere Triage.',
        ],
        'delivery_history' => [
            'description' => 'Aktuelle Zustellversuche über konfigurierte ausgehende Benachrichtigungskanäle.',
        ],
    ],
    'loading' => [
        'title' => 'Benachrichtigungsboard wird geladen',
        'description' => 'Aktuelle Monitor-Updates und Zustellversuche werden abgerufen.',
    ],
    'empty_state' => [
        'description' => 'Ungelesene Meldungen, Ablaufwarnungen und Zustellereignisse erscheinen hier, sobald sie Aufmerksamkeit benötigen.',
    ],
    'filters' => [
        'heading' => 'Ansicht',
        'unread' => 'Offen',
        'all' => 'Alle',
    ],
    'load_more' => 'Mehr laden',
    'mark_as_read' => 'Als gelesen markieren',
    'mark_all_as_read' => 'Alle als gelesen markieren',
    'read' => 'Gelesen',
    'no_notifications' => 'Nichts zu entdecken. Alles ist auf dem neuesten Stand.',
    'no_notifications_of_this_type' => 'Keine Benachrichtigungen dieses Typs.',
    'show_read_notifications' => 'Gelesene Benachrichtigungen anzeigen',
    'labels' => [
        'monitor' => 'Typ',
        'host' => 'Host',
        'timestamp' => 'Letzte Prüfung',
        'latest_status_change' => 'Letzte Statusänderung',
        'channel' => 'Kanal',
        'event' => 'Ereignis',
        'attempted_at' => 'Versucht am',
        'sent_at' => 'Gesendet am',
        'error' => 'Fehler',
        'no_status_code' => 'Kein Statuscode',
        'not_available' => 'Nicht verfügbar',
    ],
    'tooltips' => [
        'latest_status' => 'Letzter Status: :status',
    ],
    'status' => [
        'success' => 'Erfolgreich',
        'redirect' => 'Weiterleitung',
        'client_error' => 'Client-Fehler',
        'server_error' => 'Server-Fehler',
        'unknown' => 'Unbekannt',
        'maintenance' => 'Wartung',
    ],
    'status_change' => [
        'up' => 'Letzte Statusänderung: Überwachung wiederhergestellt.',
        'down' => 'Letzte Statusänderung: Überwachung ist nicht erreichbar.',
        'unknown' => 'Letzte Statusänderung: Status ist unbekannt.',
        'maintenance' => 'Letzte Statusänderung: Überwachung befindet sich im Wartungsmodus.',
    ],
    'status_messages' => [
        'up' => 'Status der Überwachung :name wurde auf VERFÜGBAR geändert',
        'down' => 'Status der Überwachung :name wurde auf NICHT VERFÜGBAR geändert',
    ],
    'ssl_messages' => [
        'expiring' => 'Das SSL-Zertifikat für :name läuft bald ab.',
        'expired' => 'Das SSL-Zertifikat für :name ist abgelaufen.',
    ],
    'domain_messages' => [
        'expiring' => 'Die Domain :name läuft bald ab.',
        'expired' => 'Die Domain :name ist abgelaufen.',
    ],
    'performance_messages' => [
        'degraded' => 'Die Antwortzeit von :name am Standort :location beträgt :current_ms ms und liegt damit :increase_percent% über dem historischen Median von :baseline_ms ms.',
        'recovered' => 'Die Antwortzeit von :name am Standort :location hat sich auf :current_ms ms erholt; der historische Median liegt bei :baseline_ms ms.',
    ],
    'channels' => [
        'slack' => 'Slack',
        'telegram' => 'Telegram',
        'discord' => 'Discord',
        'teams' => 'Microsoft Teams',
        'webhook' => 'Webhook',
        'mobile_push' => 'Mobile Push',
    ],
    'events' => [
        'incident' => 'Vorfall',
        'recovery' => 'Wiederherstellung',
        'ssl_expiring' => 'SSL läuft bald ab',
        'ssl_expired' => 'SSL abgelaufen',
        'domain_expiring' => 'Domain läuft bald ab',
        'domain_expired' => 'Domain abgelaufen',
    ],
    'delivery_status' => [
        'sent' => 'Gesendet',
        'failed' => 'Fehlgeschlagen',
        'skipped' => 'Übersprungen',
    ],
    'messages' => [
        'notification_marked_as_read' => 'Benachrichtigung als gelesen markiert.',
        'all_notifications_marked_as_read' => 'Alle Benachrichtigungen als gelesen markiert.',
    ],
];
