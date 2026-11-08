<?php

declare(strict_types=1);

namespace App\Enums;

enum IncidentDiagnosisTrigger: string
{
    case INCIDENT_OPENED = 'incident_opened';
    case RECOMPUTED = 'recomputed';
    case ON_DEMAND = 'on_demand';
}
