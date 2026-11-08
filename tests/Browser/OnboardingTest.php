<?php

declare(strict_types=1);

use App\Models\Monitoring;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\Support\BrowserGateway;
use Tests\Support\BrowserWorld;

it('registers a new account and guides it to the first monitoring', function (): void {
    $page = BrowserWorld::open('/login?mode=register');
    $inner = $page->page();

    $inner->locator('input[name="name"]')->fill('Grace Onboarding');
    $inner->locator('input[name="email"]')->fill('grace@example.test');
    $inner->locator('input[name="password"]')->fill('a-long-unique-passphrase-42');
    $inner->locator('input[name="password_confirmation"]')->fill('a-long-unique-passphrase-42');
    $inner->locator('input[name="terms"]')->check();
    $inner->locator('input[name="captcha"]')->fill('human');
    $inner->locator('form button[type="submit"]')->click();

    BrowserWorld::waitForPath($page, '/verify-email');

    $user = User::query()->where('email', 'grace@example.test')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();

    // The verification mail is not delivered in tests; verify directly to continue the flow.
    $user->forceFill(['email_verified_at' => now()])->save();
    // The in-process server shares the guard with the test, so drop the user cached by the registration request.
    Auth::forgetGuards();

    $page->navigate(BrowserGateway::url('/dashboard'));
    BrowserWorld::waitForHydration($page);
    BrowserWorld::waitForText($page, 'Set up WebGuard in two quick steps');

    $inner->getByRole('button', ['name' => 'Add your website'])->first()->click();
    $inner->getByLabel('Website URL')->fill('https://onboarding.example.test');
    $inner->locator('[role="dialog"] form button[type="submit"]')->click();

    BrowserWorld::waitForText($page, 'Website monitoring started');

    $monitoring = Monitoring::query()->where('user_id', $user->id)->firstOrFail();
    expect($monitoring->target)->toBe('https://onboarding.example.test')
        ->and($monitoring->name)->toBe('onboarding.example.test');
});

it('rejects a registration with mismatching passwords and keeps the entered data', function (): void {
    $page = BrowserWorld::open('/login?mode=register');
    $inner = $page->page();

    $inner->locator('input[name="name"]')->fill('Mismatch User');
    $inner->locator('input[name="email"]')->fill('mismatch@example.test');
    $inner->locator('input[name="password"]')->fill('a-long-unique-passphrase-42');
    $inner->locator('input[name="password_confirmation"]')->fill('something-else-entirely-1');
    $inner->locator('input[name="terms"]')->check();
    $inner->locator('input[name="captcha"]')->fill('human');
    $inner->locator('form button[type="submit"]')->click();

    BrowserWorld::waitForText($page, 'password field confirmation does not match');
    $page->assertPathIs('/login');
    expect(User::query()->where('email', 'mismatch@example.test')->exists())->toBeFalse();
});
