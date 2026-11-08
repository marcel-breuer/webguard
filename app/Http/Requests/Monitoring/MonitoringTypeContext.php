<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Enums\MonitoringType;
use Illuminate\Http\Request;

/**
 * Resolves the monitoring type of the request being validated for the per-type rule sets.
 */
class MonitoringTypeContext
{
    public function __construct(private readonly Request $request) {}

    public function request(): Request
    {
        return $this->request;
    }

    public function type(): ?MonitoringType
    {
        return MonitoringType::tryFrom((string) $this->request->input('type'));
    }

    public function is(MonitoringType $type): bool
    {
        return $this->type() === $type;
    }

    public function isHttpLike(): bool
    {
        return in_array($this->type(), [MonitoringType::HTTP, MonitoringType::KEYWORD], true);
    }
}
