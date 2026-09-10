<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringDomainResult;
use App\Models\MonitoringResponse;
use App\Models\MonitoringSslResult;
use App\Services\InstanceCallbackIdempotencyService;
use App\Services\MonitoringCheckIntervalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MonitoringController extends Controller
{
    public function storeResponse(
        Request $request,
        MonitoringCheckIntervalService $monitoringCheckIntervalService,
        InstanceCallbackIdempotencyService $instanceCallbackIdempotencyService
    ): JsonResponse {
        $validated = $request->validate([
            'monitoring_id' => ['required', 'exists:monitorings,id'],
            'status' => ['nullable', Rule::enum(MonitoringStatus::class)],
            'http_status_code' => ['nullable', 'integer', 'between:100,599'],
            'response_time' => ['nullable', 'numeric', 'min:0'],
            'check_interval_seconds' => ['nullable', 'integer', 'min:60', 'max:65535'],
            'vital_values' => ['nullable', 'array'],
            'vital_values.transport_succeeded' => ['nullable', 'boolean'],
            'vital_values.connection_succeeded' => ['nullable', 'boolean'],
            'vital_values.heartbeat_received' => ['nullable', 'boolean'],
            'vital_values.heartbeat_overdue' => ['nullable', 'boolean'],
            'vital_values.observed_values' => ['nullable', 'array'],
            'vital_values.observed_values.*' => ['string', 'max:1024'],
            'vital_values.failure_reason' => ['nullable', 'string', 'max:1024'],
        ]);

        if (! $this->isMonitoringAllowedForInstance($request, $validated['monitoring_id'])) {
            return response()->json(['message' => 'Unauthorized monitoring'], 403);
        }

        if (! isset($validated['status']) && ! isset($validated['http_status_code']) && ! isset($validated['vital_values'])) {
            return response()->json([
                'message' => 'Provide raw monitoring evidence or the legacy status during the compatibility period.',
            ], 422);
        }

        $validated['location_code'] = (string) $request->attributes->get('authenticated_instance_code');
        $validated['check_interval_seconds'] ??= $monitoringCheckIntervalService->defaultSeconds();

        return $instanceCallbackIdempotencyService->execute($request, 'monitoring-responses', function () use ($validated): JsonResponse {
            MonitoringResponse::query()->create($validated);

            return response()->json(['message' => 'Monitoring response stored successfully.']);
        });
    }

    public function storeIncident(Request $request)
    {
        $validated = $request->validate([
            'monitoring_id' => ['required', 'exists:monitorings,id'],
            'down_at' => ['required', 'date'],
        ]);

        if (! $this->isMonitoringAllowedForInstance($request, $validated['monitoring_id'])) {
            return response()->json(['message' => 'Unauthorized monitoring'], 403);
        }

        $monitoring = Monitoring::query()->findOrFail($validated['monitoring_id']);

        if (count($monitoring->preferredLocationCodes()) > 1) {
            return response()->json(['message' => 'Incident state is managed by regional consensus.']);
        }

        Incident::query()->firstOrCreate(['monitoring_id' => $validated['monitoring_id'], 'up_at' => null], $validated);

        return response()->json(['message' => 'Incident stored successfully.']);
    }

    public function updateIncident(Request $request, Monitoring $monitoring)
    {
        if (! $this->isMonitoringAllowedForInstance($request, $monitoring->id)) {
            return response()->json(['message' => 'Unauthorized monitoring'], 403);
        }

        $validated = $request->validate([
            'up_at' => ['required', 'date'],
        ]);

        if (count($monitoring->preferredLocationCodes()) > 1) {
            return response()->json(['message' => 'Incident state is managed by regional consensus.']);
        }

        $incident = Incident::query()->where('monitoring_id', $monitoring->id)
            ->whereNull('up_at')
            ->first();

        if (! $incident) {
            return response()->json(['message' => 'No open incident found for this monitoring.'], 404);
        }

        $incident->update($validated);

        return response()->json(['message' => 'Incident updated successfully.']);
    }

    public function storeSsl(Request $request, InstanceCallbackIdempotencyService $instanceCallbackIdempotencyService): JsonResponse
    {
        $validated = $request->validate([
            'monitoring_id' => ['required', 'exists:monitorings,id'],
            'is_valid' => ['required', 'boolean'],
            'expires_at' => ['nullable', 'date'],
            'issuer' => ['nullable', 'string'],
            'issued_at' => ['nullable', 'date'],
        ]);

        if (! $this->isMonitoringAllowedForInstance($request, $validated['monitoring_id'])) {
            return response()->json(['message' => 'Unauthorized monitoring'], 403);
        }

        return $instanceCallbackIdempotencyService->execute($request, 'ssl-results', function () use ($validated): JsonResponse {
            try {
                MonitoringSslResult::query()->updateOrCreate(['monitoring_id' => $validated['monitoring_id']], $validated);
            } catch (UniqueConstraintViolationException) {
                MonitoringSslResult::query()->updateOrCreate(['monitoring_id' => $validated['monitoring_id']], $validated);
            }

            return response()->json(['message' => 'SSL result stored successfully.']);
        });
    }

    public function storeDomain(Request $request, InstanceCallbackIdempotencyService $instanceCallbackIdempotencyService): JsonResponse
    {
        $validated = $request->validate([
            'monitoring_id' => ['required', Rule::exists('monitorings', 'id')->where('type', MonitoringType::DOMAIN_EXPIRATION->value)],
            'is_valid' => ['required', 'boolean'],
            'expires_at' => ['nullable', 'date'],
            'registrar' => ['nullable', 'string', 'max:255'],
            'checked_at' => ['nullable', 'date'],
        ]);

        if (! $this->isMonitoringAllowedForInstance($request, $validated['monitoring_id'])) {
            return response()->json(['message' => 'Unauthorized monitoring'], 403);
        }

        return $instanceCallbackIdempotencyService->execute($request, 'domain-results', function () use ($validated): JsonResponse {
            MonitoringDomainResult::query()->updateOrCreate(['monitoring_id' => $validated['monitoring_id']], $validated);

            return response()->json(['message' => 'Domain expiration result stored successfully.']);
        });
    }

    private function isMonitoringAllowedForInstance(Request $request, string $monitoringId): bool
    {
        $instanceCode = (string) $request->attributes->get('authenticated_instance_code');

        if ($instanceCode === '') {
            return false;
        }

        return Monitoring::query()
            ->where('id', $monitoringId)
            ->assignedToLocation($instanceCode)
            ->exists();
    }
}
