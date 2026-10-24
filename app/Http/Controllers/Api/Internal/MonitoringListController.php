<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Enums\MonitoringType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Instance\MonitoringResource;
use App\Models\Monitoring;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MonitoringListController extends Controller
{
    public function __invoke(Request $request)
    {
        $authenticatedInstanceCode = (string) $request->attributes->get('authenticated_instance_code');
        $requestedLocation = (string) $request->query('location', '');

        if ($requestedLocation !== '' && $authenticatedInstanceCode !== '' && $requestedLocation !== $authenticatedInstanceCode) {
            return response()->json(['message' => 'Unauthorized location'], 403);
        }

        $validated = $request->validate([
            'location' => ['required', 'string', Rule::exists('server_instances', 'code')->where('is_active', true)],
            'type' => ['nullable', 'string', Rule::in(array_column(MonitoringType::cases(), 'value'))],
        ]);

        $location = $validated['location'];
        $type = $validated['type'] ?? null;

        $builder = Monitoring::query()
            ->where('status', 'active')
            ->assignedToLocation($location)
            ->with([
                'domainResult',
                'latestResponseResult',
            ])
            ->withMax([
                'responseResults as latest_response_at_for_location' => fn ($query) => $query->where('location_code', $location),
            ], 'created_at');

        if ($type) {
            $builder->where('type', $type);
        } else {
            $builder->whereNotIn('type', [
                MonitoringType::HEARTBEAT->value,
                MonitoringType::SERVER_HEALTH->value,
            ]);
        }

        $monitorings = $builder->get();

        return MonitoringResource::collection($monitorings);
    }
}
