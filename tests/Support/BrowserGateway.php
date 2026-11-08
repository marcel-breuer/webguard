<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Pest\Browser\ServerManager;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Serves the built SvelteKit application and the in-process Laravel test server
 * behind the production gateway routing, so browser tests exercise the same
 * same-origin topology as the deployed stack while sharing the test database.
 */
final class BrowserGateway
{
    private const string HOST = '127.0.0.1';

    private const int READINESS_TIMEOUT_SECONDS = 30;

    private static ?self $instance = null;

    private ?Process $frontend = null;

    private ?Process $gateway = null;

    private function __construct(
        private readonly int $gatewayPort,
        private readonly int $frontendPort,
        private readonly int $laravelPort,
        private readonly string $runtimeDirectory,
    ) {}

    public static function url(string $path = '/'): string
    {
        $gateway = self::$instance ??= self::boot();
        $gateway->pointApplicationAtGateway();

        return $gateway->origin() . '/' . mb_ltrim($path, '/');
    }

    public function stop(): void
    {
        foreach ([$this->gateway, $this->frontend] as $process) {
            if ($process instanceof Process && $process->isRunning()) {
                $process->stop(5);
            }
        }
    }

    private static function boot(): self
    {
        $server = ServerManager::instance()->http();
        $server->bootstrap();
        $laravelUrl = $server->rewrite('/');
        $laravelPort = parse_url($laravelUrl, PHP_URL_PORT);

        if (! is_int($laravelPort)) {
            throw new RuntimeException(sprintf('The browser test server URL [%s] has no port.', $laravelUrl));
        }

        $runtimeDirectory = storage_path('framework/testing/browser-gateway/' . getmypid());

        if (! is_dir($runtimeDirectory) && ! mkdir($runtimeDirectory, 0o775, true) && ! is_dir($runtimeDirectory)) {
            throw new RuntimeException(sprintf('The browser gateway directory [%s] could not be created.', $runtimeDirectory));
        }

        $gateway = new self(self::freePort(), self::freePort(), $laravelPort, $runtimeDirectory);
        $gateway->startFrontend();
        $gateway->startGateway();

        register_shutdown_function($gateway->stop(...));

        return $gateway;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://' . self::HOST . ':0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException(sprintf('No free port is available: %s', $errorMessage));
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) mb_substr($name, (int) mb_strrpos($name, ':') + 1);
    }

    private function origin(): string
    {
        return sprintf('http://%s:%d', self::HOST, $this->gatewayPort);
    }

    private function pointApplicationAtGateway(): void
    {
        $origin = $this->origin();

        config([
            'app.url' => $origin,
            'sanctum.stateful' => [self::HOST . ':' . $this->gatewayPort],
        ]);

        URL::useOrigin($origin);
        URL::useAssetOrigin($origin);
    }

    private function startFrontend(): void
    {
        $entrypoint = base_path('frontend/build/index.js');

        throw_unless(is_file($entrypoint), RuntimeException::class, 'Build the SvelteKit frontend with `bun run frontend:build` before running browser tests.');

        $this->frontend = new Process(
            [(string) env('BROWSER_FRONTEND_RUNTIME', 'bun'), $entrypoint],
            base_path('frontend'),
            [
                'HOST' => self::HOST,
                'PORT' => (string) $this->frontendPort,
                'HOST_HEADER' => 'X-Forwarded-Host',
                'PROTOCOL_HEADER' => 'X-Forwarded-Proto',
                'NODE_ENV' => 'production',
            ],
        );
        $this->frontend->setTimeout(null);
        $this->frontend->start();

        $this->waitUntilReachable($this->frontend, sprintf('http://%s:%d/_health/frontend', self::HOST, $this->frontendPort));
    }

    private function startGateway(): void
    {
        $configuration = $this->runtimeDirectory . '/nginx.conf';
        file_put_contents($configuration, $this->gatewayConfiguration());

        $this->gateway = new Process(
            [(string) env('BROWSER_GATEWAY_NGINX', $this->nginxBinary()), '-p', $this->runtimeDirectory, '-c', $configuration, '-e', $this->runtimeDirectory . '/error.log'],
        );
        $this->gateway->setTimeout(null);
        $this->gateway->start();

        $this->waitUntilReachable($this->gateway, $this->origin() . '/_health/gateway');
    }

    private function nginxBinary(): string
    {
        return (new ExecutableFinder)->find('nginx', 'nginx', ['/usr/sbin', '/usr/local/sbin', '/opt/homebrew/bin']);
    }

    /**
     * Rewrites only addresses and filesystem paths so the routing rules stay
     * identical to the deployed gateway; a changed anchor fails loudly.
     */
    private function gatewayConfiguration(): string
    {
        $configuration = (string) file_get_contents(base_path('docker/gateway/nginx.conf'));
        $directory = $this->runtimeDirectory;

        $replacements = [
            'worker_processes auto;' => "daemon off;\npid {$directory}/nginx.pid;\nworker_processes 1;",
            'access_log /var/log/nginx/access.log main;' => "access_log {$directory}/access.log main;\n"
                . "    client_body_temp_path {$directory}/client-body;\n"
                . "    proxy_temp_path {$directory}/proxy;\n"
                . "    fastcgi_temp_path {$directory}/fastcgi;\n"
                . "    uwsgi_temp_path {$directory}/uwsgi;\n"
                . "    scgi_temp_path {$directory}/scgi;",
            'error_log /var/log/nginx/error.log warn;' => "error_log {$directory}/error.log warn;",
            'include /etc/nginx/proxy-headers.conf;' => 'include ' . base_path('docker/gateway/proxy-headers.conf') . ';',
            'server php:8080;' => sprintf('server %s:%d;', self::HOST, $this->laravelPort),
            'server frontend:3000;' => sprintf('server %s:%d;', self::HOST, $this->frontendPort),
            'listen 8080;' => sprintf('listen %s:%d;', self::HOST, $this->gatewayPort),
        ];

        foreach ($replacements as $search => $replacement) {
            if (! str_contains($configuration, $search)) {
                throw new RuntimeException(sprintf('The gateway configuration no longer contains [%s].', $search));
            }

            $configuration = str_replace($search, $replacement, $configuration);
        }

        return $configuration;
    }

    private function waitUntilReachable(Process $process, string $url): void
    {
        $deadline = microtime(true) + self::READINESS_TIMEOUT_SECONDS;
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);

        while (microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                throw new RuntimeException(sprintf(
                    "Browser test process [%s] exited early:\n%s%s",
                    $process->getCommandLine(),
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ));
            }

            if (@file_get_contents($url, false, $context) !== false) {
                return;
            }

            Sleep::usleep(100_000);
        }

        throw new RuntimeException(sprintf('Browser test process [%s] did not become reachable at %s.', $process->getCommandLine(), $url));
    }
}
