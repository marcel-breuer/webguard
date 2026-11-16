<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | External API token abilities
    |--------------------------------------------------------------------------
    |
    | Kept disabled by default so existing personal access tokens retain their
    | current access. Enable only after issuing tokens with external:read and
    | external:write abilities.
    |
    */
    'enforce_token_abilities' => (bool) env('EXTERNAL_API_ENFORCE_TOKEN_ABILITIES', false),

    /*
    |--------------------------------------------------------------------------
    | Token request rate limit
    |--------------------------------------------------------------------------
    |
    | Maximum requests per 60-second window and user for personal access tokens
    | and scoped API keys on the shared REST routes. MCP tool calls dispatch
    | through the same routes and count against the same budget. Mobile app
    | tokens are not limited by this setting.
    |
    */
    'rate_limit_per_minute' => (int) env('EXTERNAL_API_RATE_LIMIT_PER_MINUTE', 60),
];
