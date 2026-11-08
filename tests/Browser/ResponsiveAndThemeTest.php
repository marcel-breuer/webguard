<?php

declare(strict_types=1);

use App\Models\StatusPage;
use Tests\Support\BrowserGateway;
use Tests\Support\BrowserWorld;

// Same measure as the SvelteKit topology smoke test: content must not widen the page beyond the viewport.
define('NO_HORIZONTAL_OVERFLOW', 'document.body.scrollWidth <= document.documentElement.clientWidth');

// The 30-day calendar renders invisible tooltips that can extend the scroll width by a few pixels, so the public page is checked for visible content only.
define('NO_VISIBLE_HORIZONTAL_OVERFLOW', 'Array.from(document.querySelectorAll("body *")).every((element) => getComputedStyle(element).visibility === "hidden" || element.getBoundingClientRect().right <= document.documentElement.clientWidth + 1)');

it('renders a public status page without a session', function (): void {
    $owner = BrowserWorld::user();
    $statusPage = StatusPage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Acme Public Status',
        'slug' => 'acme-public-status',
        'description' => 'Availability of the Acme services.',
        'is_public' => true,
    ]);

    $page = BrowserWorld::open('/status/' . $statusPage->id);

    $page->assertSee('Acme Public Status')
        ->assertSee('Subscribe to updates')
        ->assertSee('Recent incidents')
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_VISIBLE_HORIZONTAL_OVERFLOW);
});

it('keeps the public status page usable on a mobile viewport', function (): void {
    $owner = BrowserWorld::user();
    $statusPage = StatusPage::query()->create([
        'user_id' => $owner->id,
        'name' => 'Acme Mobile Status',
        'slug' => 'acme-mobile-status',
        'is_public' => true,
    ]);

    $page = BrowserWorld::open('/status/' . $statusPage->id);
    $page->resize(390, 844);

    // Layout settles after the viewport change, so poll instead of asserting once.
    BrowserWorld::waitUntil($page, NO_VISIBLE_HORIZONTAL_OVERFLOW);
    $page->assertSee('Acme Mobile Status')
        ->assertScript(NO_VISIBLE_HORIZONTAL_OVERFLOW);
});

it('collapses the dashboard navigation behind a toggle on a mobile viewport', function (): void {
    $user = BrowserWorld::user();

    $page = BrowserWorld::signedInAt($user, '/dashboard');
    $inner = $page->page();
    $page->resize(390, 844);
    BrowserWorld::waitUntil($page, 'document.getElementById("app-navigation")?.hasAttribute("inert") === true');

    BrowserWorld::waitUntil($page, NO_HORIZONTAL_OVERFLOW);
    $page->assertScript(NO_HORIZONTAL_OVERFLOW);

    $inner->locator('button[aria-label="Toggle navigation"]')->click();
    BrowserWorld::waitUntil($page, 'document.getElementById("app-navigation")?.hasAttribute("inert") === false');

    $inner->locator('#app-navigation a[aria-label="Monitorings"]')->click();
    BrowserWorld::waitForPath($page, '/monitorings');
    BrowserWorld::waitUntil($page, 'document.getElementById("app-navigation")?.hasAttribute("inert") === true');
});

it('applies the dark theme stored on the user profile', function (): void {
    $user = BrowserWorld::user(['theme' => 'dark']);

    $page = BrowserWorld::signedInAt($user, '/dashboard');
    BrowserWorld::waitUntil($page, 'document.documentElement.classList.contains("dark")');

    $page->assertScript('document.documentElement.dataset.theme', 'dark');
});

it('follows the system dark preference when the profile uses the system theme', function (): void {
    $user = BrowserWorld::user(['theme' => 'system']);

    $page = test()->visit(BrowserGateway::url('/login'))->inDarkMode();
    BrowserWorld::waitForHydration($page);
    BrowserWorld::signIn($page, $user->email);
    BrowserWorld::waitForPath($page, '/dashboard');

    BrowserWorld::waitUntil($page, 'document.documentElement.classList.contains("dark")');
    $page->assertScript('document.documentElement.dataset.theme', 'system');
});
