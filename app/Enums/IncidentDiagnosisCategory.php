<?php

declare(strict_types=1);

namespace App\Enums;

enum IncidentDiagnosisCategory: string
{
    case MAINTENANCE = 'maintenance';
    case CERTIFICATE = 'certificate';
    case DOMAIN = 'domain';
    case DNS = 'dns';
    case NETWORK_REGIONAL = 'network_regional';
    case CONNECTIVITY = 'connectivity';
    case SERVER_ERROR = 'server_error';
    case CLIENT_ERROR = 'client_error';
    case TIMEOUT_PERFORMANCE = 'timeout_performance';
    case CONFIG_CHANGE = 'config_change';
    case SHARED_DEPENDENCY = 'shared_dependency';
    case MISSED_HEARTBEAT = 'missed_heartbeat';
    case SERVER_RESOURCES = 'server_resources';
    case UNKNOWN = 'unknown';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return __('incidents.diagnosis.categories.' . $this->value);
    }
}
