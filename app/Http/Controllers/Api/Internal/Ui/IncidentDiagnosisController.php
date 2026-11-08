<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\IncidentDiagnosisAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IncidentDiagnosisController extends Controller
{
    private const int OPEN_INCIDENT_LIMIT = 50;

    public function __construct(private readonly IncidentDiagnosisAccessService $incidentDiagnosisAccessService) {}

    public function open(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $incidents = $this->incidentDiagnosisAccessService->listOpen($user, self::OPEN_INCIDENT_LIMIT);

        return response()->json([
            'data' => $incidents
                ->map(fn ($incident): array => $this->incidentDiagnosisAccessService->payload($incident))
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, string $incident): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->incidentDiagnosisAccessService->findVisible($user, $incident);

        return response()->json(['data' => $this->incidentDiagnosisAccessService->payloadWithBackfill($model)]);
    }

    public function recompute(Request $request, string $incident): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->isDemo(), 403);
        $model = $this->incidentDiagnosisAccessService->findVisible($user, $incident);

        return response()->json(['data' => $this->incidentDiagnosisAccessService->recompute($model)]);
    }
}
