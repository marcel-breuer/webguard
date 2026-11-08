<?php

declare(strict_types=1);

use Tests\Support\BrowserWorld;

it('renders the notification channel settings and saves a Slack channel', function (): void {
    $user = BrowserWorld::user();

    $page = BrowserWorld::signedInAt($user, '/profile/notifications');
    $inner = $page->page();

    $page->assertSee('Notification settings');
    $page->assertSee('Microsoft Teams');

    $inner->locator('input[name="notification_channels[slack][enabled]"]')->check();
    $inner->locator('input[name="notification_channels[slack][webhook_url]"]')
        ->fill('https://hooks.slack.com/services/T000/B000/BROWSER');
    $inner->getByRole('button', ['name' => 'Save notification settings'])->click();

    // The page reloads after a successful save, so assert the persisted state instead of the transient message.
    BrowserWorld::waitForState(
        $page,
        fn (): bool => ($user->fresh()->notification_channels['slack']['enabled'] ?? false) === true,
        'the Slack channel to be saved',
    );
    BrowserWorld::waitForHydration($page);
    $page->assertScript('document.querySelector(\'input[name="notification_channels[slack][webhook_url]"]\').value', 'https://hooks.slack.com/services/T000/B000/BROWSER');

    $slack = $user->fresh()->notification_channels['slack'] ?? [];
    expect($slack['enabled'] ?? null)->toBeTrue()
        ->and($slack['webhook_url'] ?? null)->toBe('https://hooks.slack.com/services/T000/B000/BROWSER');
});

it('rejects a webhook that points at a private address', function (): void {
    $user = BrowserWorld::user();

    $page = BrowserWorld::signedInAt($user, '/profile/notifications');
    $inner = $page->page();

    $inner->locator('input[name="notification_channels[webhook][enabled]"]')->check();
    $inner->locator('input[name="notification_channels[webhook][url]"]')->fill('http://127.0.0.1:8080/notifications');
    $inner->getByRole('button', ['name' => 'Save notification settings'])->click();

    $inner->locator('[role="alert"]')->first()->waitFor();
    expect($user->fresh()->notification_channels['webhook']['url'] ?? null)->not->toBe('http://127.0.0.1:8080/notifications');
});
