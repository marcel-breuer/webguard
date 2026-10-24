<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Mobile\Concerns;

trait RequiresMobileIdempotencyKey
{
    protected function prepareMobileIdempotencyKey(): void
    {
        $this->merge([
            'idempotency_key' => mb_trim((string) $this->header('Idempotency-Key')),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function mobileIdempotencyKeyRules(): array
    {
        return ['required', 'string', 'max:100', 'not_regex:/^webguard-auto-status-/i'];
    }
}
