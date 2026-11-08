<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PubliclyRoutableUrl
{
    public static function allows(string $url, bool $resolveDns = false): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

        if (! is_string($host) || ! is_string($scheme)) {
            return false;
        }

        if (! in_array(mb_strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        return self::isPublicHost($host, $resolveDns);
    }

    /**
     * Resolve a webhook URL to a public address that can be pinned for the
     * subsequent request. This closes the validation-to-use DNS rebinding gap.
     *
     * @return array{host: string, ip: string, port: int}|null
     */
    public static function destination(string $url): ?array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

        if (! is_string($host) || ! is_string($scheme)) {
            return null;
        }

        $scheme = mb_strtolower($scheme);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (str_ends_with($host, '.')) {
            return null;
        }

        $host = self::normalizeHost($host);

        if ($host === '' || ! self::isPublicHost($host, false)) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if ($port < 1) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host)
                ? ['host' => $host, 'ip' => $host, 'port' => $port]
                : null;
        }

        $addresses = self::resolveHost($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                return null;
            }
        }

        return ['host' => $host, 'ip' => $addresses[0], 'port' => $port];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function post(string $url, array $payload): Response
    {
        $destination = self::destination($url);

        throw_unless($destination !== null, RuntimeException::class, 'Notification webhook URL is not publicly routable.');

        $options = [
            'allow_redirects' => false,
        ];

        if (! filter_var($destination['host'], FILTER_VALIDATE_IP)) {
            throw_unless(defined('CURLOPT_RESOLVE'), RuntimeException::class, 'Webhook delivery requires the cURL extension.');

            $options['curl'] = [
                CURLOPT_RESOLVE => [
                    sprintf('%s:%d:%s', $destination['host'], $destination['port'], $destination['ip']),
                ],
            ];
        }

        return Http::timeout(10)
            ->withOptions($options)
            ->post($url, $payload);
    }

    private static function isPublicHost(string $host, bool $resolveDns): bool
    {
        $host = self::normalizeHost($host);

        if ($host === '' || $host === 'localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        if (
            ! str_contains($host, '.')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
        ) {
            return false;
        }

        if (! $resolveDns) {
            return true;
        }

        $addresses = self::resolveHost($host);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                return false;
            }
        }

        return true;
    }

    private static function normalizeHost(string $host): string
    {
        return mb_strtolower(mb_trim($host, "[] \t\n\r\0\x0B."));
    }

    private static function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && is_string($record[$key])) {
                    $addresses[] = $record[$key];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
