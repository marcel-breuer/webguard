<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Package;
use App\Models\ServerInstance;
use App\Models\User;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use RuntimeException;

/**
 * Shared deterministic fixtures and helpers for browser tests.
 */
final class BrowserWorld
{
    public const string PASSWORD = 'password';

    /**
     * The in-process Laravel server of the browser plugin only parses urlencoded
     * request bodies. The UI submits FormData as multipart, so this shim re-encodes
     * those bodies in the browser; the application code under test is unchanged.
     */
    private const string FORM_TRANSPORT_SHIM = <<<'JS'
        function () {
            if (window.__formTransportShim) {
                return true;
            }

            const nativeFetch = window.fetch.bind(window);
            window.fetch = (input, init) => {
                if (init && init.body instanceof FormData) {
                    const body = new URLSearchParams();
                    init.body.forEach((value, key) => body.append(key, String(value)));
                    init = { ...init, body: body.toString(), headers: { ...init.headers, 'Content-Type': 'application/x-www-form-urlencoded' } };
                }

                return nativeFetch(input, init);
            };
            window.__formTransportShim = true;

            return true;
        }
        JS;

    /**
     * Browser requests span several PHP requests, so the session must outlive one
     * request, and the image-based captcha cannot be solved by a test.
     */
    public static function prepare(): void
    {
        config([
            'session.driver' => 'database',
            'captcha.disable' => true,
        ]);

        Package::factory()->create(['monitoring_limit' => 5, 'price' => 0, 'is_selectable' => true]);
        ServerInstance::query()->create([
            'code' => 'browser-1',
            'ip_address' => '192.0.2.10',
            'api_key_hash' => 'browser-test-token-1234567890',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /**
     * Opens a gateway path and waits until SvelteKit has hydrated, so form
     * submissions are handled by the client instead of a native page reload.
     */
    public static function open(string $path): PendingAwaitablePage|AwaitableWebpage
    {
        $page = test()->visit(BrowserGateway::url($path));
        self::waitForHydration($page);

        return $page;
    }

    public static function waitForHydration(PendingAwaitablePage|AwaitableWebpage $page): void
    {
        self::waitUntil($page, 'document.documentElement.dataset.hydrated === "true"');
        $page->script(self::FORM_TRANSPORT_SHIM);
    }

    public static function signIn(PendingAwaitablePage|AwaitableWebpage $page, string $email, string $password = self::PASSWORD): void
    {
        $inner = $page->page();
        $inner->locator('input[name="email"]')->fill($email);
        $inner->locator('input[name="password"]')->fill($password);
        $inner->locator('form button[type="submit"]')->click();
    }

    /**
     * Signs the user in through the login form and opens the requested path.
     */
    public static function signedInAt(User $user, string $path): PendingAwaitablePage|AwaitableWebpage
    {
        $page = self::open('/login');
        self::signIn($page, $user->email);
        self::waitForPath($page, '/dashboard');
        self::waitForHydration($page);

        if ($path !== '/dashboard') {
            $page->navigate(BrowserGateway::url($path));
            self::waitForHydration($page);
        }

        return $page;
    }

    /**
     * Polls a JavaScript condition. The pause yields to the event loop so the
     * in-process Laravel server keeps answering the browser's requests.
     */
    public static function waitUntil(PendingAwaitablePage|AwaitableWebpage $page, string $expression, int $timeoutSeconds = 20): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if ($page->script('function() { return Boolean(' . $expression . '); }') === true) {
                return;
            }

            $page->wait(0.2);
        } while (microtime(true) < $deadline);

        self::captureFailure($page);
        $visibleText = mb_substr((string) $page->script('function() { return document.body.innerText; }'), 0, 500);

        throw new RuntimeException(sprintf("Timed out after %ds waiting for [%s] on %s.\nVisible text: %s", $timeoutSeconds, $expression, $page->url(), $visibleText));
    }

    /**
     * Polls server-side state that a browser action is expected to persist.
     *
     * @param  callable(): bool  $condition
     */
    public static function waitForState(PendingAwaitablePage|AwaitableWebpage $page, callable $condition, string $description, int $timeoutSeconds = 20): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if ($condition()) {
                return;
            }

            $page->wait(0.2);
        } while (microtime(true) < $deadline);

        self::captureFailure($page);

        throw new RuntimeException(sprintf('Timed out after %ds waiting for %s.', $timeoutSeconds, $description));
    }

    public static function waitForText(PendingAwaitablePage|AwaitableWebpage $page, string $text): void
    {
        self::waitUntil($page, 'document.body.innerText.includes(' . json_encode($text, JSON_THROW_ON_ERROR) . ')');
    }

    public static function waitForPath(PendingAwaitablePage|AwaitableWebpage $page, string $path): void
    {
        self::waitUntil($page, 'window.location.pathname === ' . json_encode($path, JSON_THROW_ON_ERROR));
    }

    /**
     * Saves a screenshot under tests/Browser/Screenshots, which CI uploads when the job fails.
     */
    private static function captureFailure(PendingAwaitablePage|AwaitableWebpage $page): void
    {
        $page->screenshot(true, 'timeout-' . Str::slug(test()->name()));
    }
}
