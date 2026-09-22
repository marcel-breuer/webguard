<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ServerInstance;

final class MonitoringLocationLabel
{
    public function for(ServerInstance $serverInstance): string
    {
        $displayName = mb_trim((string) $serverInstance->display_name);
        $code = mb_trim((string) $serverInstance->code);

        if ($displayName !== '' && ! $this->isGenericName($displayName, $code)) {
            return $displayName;
        }

        $parts = array_filter([
            mb_trim((string) $serverInstance->region),
            $this->countryCode($serverInstance->country_code),
        ]);
        $parts = array_values(array_unique($parts));

        return $parts === [] ? $code : implode(', ', $parts);
    }

    private function isGenericName(string $displayName, string $code): bool
    {
        return strcasecmp($displayName, $code) === 0
            || preg_match('/^(?:location|standort)[\s_-]*\d+$/iu', $displayName) === 1;
    }

    private function countryCode(?string $countryCode): ?string
    {
        $countryCode = mb_strtoupper(mb_trim((string) $countryCode));

        return preg_match('/^[A-Z]{2}$/', $countryCode) === 1 ? $countryCode : null;
    }
}
