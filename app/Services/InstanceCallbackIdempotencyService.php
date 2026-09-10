<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InstanceCallbackIdempotency;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class InstanceCallbackIdempotencyService
{
    private const int RETENTION_HOURS = 24;

    /**
     * Execute an instance callback once and replay its response for retries.
     *
     * Requests without an idempotency key deliberately retain the legacy
     * behavior so older scanner instances can be rolled out independently.
     *
     * @param  Closure(): JsonResponse  $callback
     */
    public function execute(Request $request, string $endpoint, Closure $callback): JsonResponse
    {
        $idempotencyKey = mb_trim((string) $request->header('Idempotency-Key'));

        if ($idempotencyKey === '') {
            return $callback();
        }

        if (! Str::isUuid($idempotencyKey) || mb_strtolower($idempotencyKey[14]) !== '4') {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'The Idempotency-Key header must contain a valid UUID.',
            ]);
        }

        $instanceCode = (string) $request->attributes->get('authenticated_instance_code');

        throw_if($instanceCode === '', LogicException::class, 'An authenticated instance code is required for callback idempotency.');

        $requestHash = $this->requestHash($request);

        try {
            return DB::transaction(function () use ($callback, $endpoint, $idempotencyKey, $instanceCode, $requestHash): JsonResponse {
                $record = InstanceCallbackIdempotency::query()
                    ->where('instance_code', $instanceCode)
                    ->where('endpoint', $endpoint)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($record && $record->expires_at?->isFuture()) {
                    return $this->replayOrReject($record, $requestHash);
                }

                $record?->delete();
                $jsonResponse = $callback();
                $responseBody = $jsonResponse->getData(true);

                throw_unless(is_array($responseBody), LogicException::class, 'Instance callback responses must be JSON objects or arrays.');

                InstanceCallbackIdempotency::query()->create([
                    'instance_code' => $instanceCode,
                    'endpoint' => $endpoint,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response_status' => $jsonResponse->getStatusCode(),
                    'response_body' => $responseBody,
                    'expires_at' => now()->addHours(self::RETENTION_HOURS),
                ]);

                return $jsonResponse;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $record = InstanceCallbackIdempotency::query()
                ->where('instance_code', $instanceCode)
                ->where('endpoint', $endpoint)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            throw_if(! $record || $record->expires_at?->isPast(), $exception);

            return $this->replayOrReject($record, $requestHash);
        }
    }

    private function replayOrReject(InstanceCallbackIdempotency $instanceCallbackIdempotency, string $requestHash): JsonResponse
    {
        if ($instanceCallbackIdempotency->request_hash !== $requestHash) {
            return response()->json([
                'message' => 'Idempotency key was already used with a different request.',
            ], 409);
        }

        return response()->json($instanceCallbackIdempotency->response_body, $instanceCallbackIdempotency->response_status);
    }

    private function requestHash(Request $request): string
    {
        $payload = $this->canonicalize($request->all());

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        $canonical = [];
        ksort($value);

        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalize($item);
        }

        return $canonical;
    }
}
