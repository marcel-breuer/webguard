<?php

declare(strict_types=1);

return [
    'title' => 'API',
    'text' => 'Verwalten Sie Ihre API-Schlüssel und Zugriffstoken für sichere API-Interaktionen.',
    'configuration' => [
        'heading' => 'API-Konfiguration',
        'description' => 'Erstellen Sie getrennte Schlüssel mit minimalen Berechtigungen für jede Integration. Ein Schlüssel wird nur einmal angezeigt; danach bleiben nur sichere Metadaten sichtbar.',
        'fields' => [
            'token' => 'Ihr API-Token',
            'name' => 'Schlüsselname',
            'abilities' => 'Berechtigungen',
            'last_used_at' => 'Zuletzt verwendet',
            'status' => 'Status',
        ],
        'actions' => [
            'create_key' => 'API-Schlüssel erstellen',
            'copy' => 'Kopieren',
            'revoke_key' => 'Schlüssel widerrufen',
        ],
        'messages' => [
            'copied' => 'API-Schlüssel in die Zwischenablage kopiert!',
            'confirm_revoke_key' => 'Möchten Sie diesen API-Schlüssel wirklich widerrufen? Integrationen, die ihn verwenden, funktionieren danach sofort nicht mehr.',
            'created' => 'API-Schlüssel erstellt. Kopieren Sie ihn jetzt: Er wird nicht erneut angezeigt.',
            'revoked' => 'API-Schlüssel widerrufen.',
            'api_key_confidential_warning' => 'Halten Sie Ihren API-Schlüssel vertraulich. Wenn Sie glauben, dass Ihr Schlüssel kompromittiert wurde, können Sie einen neuen generieren.',
        ],
        'abilities' => [
            'server_health_write' => 'Server Health schreiben — nur Telemetrie senden',
            'analytics_read' => 'Analytics lesen — nur unterstützte Reporting-Endpunkte verwenden',
            'management_read' => 'Verwaltung lesen — Monitorings, Statusseiten, Vorfälle, Wartung und Teams lesen',
            'management_write' => 'Verwaltung schreiben — diese Ressourcen anlegen, ändern und löschen',
        ],
        'active' => 'Aktiv',
        'revoked' => 'Widerrufen',
        'never_used' => 'Nie',
    ],
    'logs' => [
        'title' => 'API-Protokolle',
        'description' => 'Zeigen Sie Ihre API-Nutzungsprotokolle an, um Anfragen und Antworten zu überwachen.',
        'fields' => [
            'date' => 'Datum',
            'email' => 'E-Mail',
            'endpoint' => 'Endpunkt',
        ],
        'messages' => [
            'no_logs' => 'Keine API-Protokolle gefunden.',
        ],
    ],
    'docs' => [
        'heading' => 'API-Referenz',
        'description' => 'Prüfen Sie Monitoring-Endpunkte, Authentifizierung und Request-Beispiele für Ihre Integrationen.',
        'link' => 'API-Referenz öffnen.',
    ],
];
