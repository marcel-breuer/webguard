<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Http\Controllers\Controller;
use App\Http\Resources\InternalUi\MonitoringResource;
use App\Models\ServerInstance;
use App\Models\User;
use App\Queries\MonitoringDetailQuery;
use App\Services\MonitoringCheckIntervalService;
use App\Support\MonitoringLocationLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringShowController extends Controller
{
    public function __invoke(
        Request $request,
        string $monitoring,
        MonitoringDetailQuery $monitoringDetailQuery,
        MonitoringCheckIntervalService $monitoringCheckIntervalService,
        MonitoringLocationLabel $locationLabel,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $monitoring = $monitoringDetailQuery->findVisible($user, $monitoring);
        $payload = MonitoringResource::make($monitoring)->resolve($request);
        $locationCodes = $monitoring->preferredLocationCodes();
        $locationsByCode = ServerInstance::query()
            ->whereIn('code', $locationCodes)
            ->get(['code', 'display_name', 'country_code', 'region'])
            ->keyBy('code');
        $payload['check_locations'] = array_map(
            static function (string $code) use ($locationsByCode, $locationLabel): array {
                $location = $locationsByCode->get($code);

                return [
                    'code' => $code,
                    'name' => $location instanceof ServerInstance ? $locationLabel->for($location) : $code,
                ];
            },
            $locationCodes,
        );
        $payload['initial_results_wait_minutes'] = $monitoring->isActive()
            && $monitoring->latestResponseResult === null
            && ! $monitoring->isHeartbeat()
            && ! $monitoring->isServerHealth()
            ? (int) ceil($monitoringCheckIntervalService->secondsFor($monitoring) / 60)
            : null;

        return response()->json(['data' => $payload]);
    }
}
