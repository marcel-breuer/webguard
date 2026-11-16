<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Enums\ApiKeyAbility;
use App\Models\PersonalAccessToken;
use App\Services\ApiKeyService;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * What the calling token may do through MCP.
 *
 * Mirrors the REST rules: scoped keys carry explicit abilities, while legacy personal
 * access tokens follow the `external:read` / `external:write` abilities (wildcard tokens
 * keep full access). Write access is never implied by read access.
 */
final readonly class McpAccess
{
    public function __construct(
        public bool $analytics,
        public bool $read,
        public bool $write,
    ) {}

    public static function fromToken(?HasAbilities $token): self
    {
        if (! $token instanceof PersonalAccessToken) {
            return new self(true, true, true);
        }

        if (ApiKeyService::isManagedKey($token)) {
            $read = $token->can(ApiKeyAbility::MANAGEMENT_READ->value);

            return new self(
                analytics: $read || $token->can(ApiKeyAbility::ANALYTICS_READ->value),
                read: $read,
                write: $token->can(ApiKeyAbility::MANAGEMENT_WRITE->value),
            );
        }

        $read = ! config('external_api.enforce_token_abilities') || $token->can('external:read');

        return new self($read, $read, $token->can('external:write'));
    }

    public function canUseMcp(): bool
    {
        return $this->analytics || $this->read || $this->write;
    }
}
