<?php

declare(strict_types=1);

use App\Enums\MonitoringType;
use App\Models\Monitoring;
use Tests\Support\BrowserWorld;

it('opens global search from another page and navigates to a monitoring by target', function (): void {
    $user = BrowserWorld::user();
    $monitoring = Monitoring::factory()->for($user)->create([
        'type' => MonitoringType::HTTP,
        'name' => 'Search destination',
        'target' => 'https://shortcut.example.test',
    ]);
    Monitoring::factory()->create(['name' => 'Hidden shortcut', 'target' => 'https://shortcut.example.test']);
    $page = BrowserWorld::signedInAt($user, '/profile');
    $inner = $page->page();
    $inner->locator('body')->press('Alt+k');
    BrowserWorld::waitUntil($page, 'document.activeElement?.id === "monitoring-search-input"');
    $inner->locator('#monitoring-search-input')->fill('shortcut.example');
    $inner->locator('#monitoring-search-results [role="option"]')->first()->waitFor();
    $page->assertScript('document.querySelectorAll("#monitoring-search-results [role=option]").length', 1);
    $inner->locator('#monitoring-search-results [role="option"]')->first()->click();
    BrowserWorld::waitForPath($page, '/monitorings/' . $monitoring->id);
    BrowserWorld::waitUntil($page, 'document.querySelector("#monitoring-search-input") === null');
    $page->assertSee('Search destination')->assertNoJavaScriptErrors();
});

it('handles the mac option key character and keyboard selection with focus restoration', function (): void {
    $user = BrowserWorld::user();
    Monitoring::factory()->for($user)->create(['name' => 'Keyboard Alpha']);
    $second = Monitoring::factory()->for($user)->create(['name' => 'Keyboard Beta']);
    $page = BrowserWorld::signedInAt($user, '/dashboard');
    $inner = $page->page();
    $page->script('function() { const button = document.querySelector("#app-navigation button[aria-label=\'Search monitorings\']"); button.focus(); window.dispatchEvent(new KeyboardEvent("keydown", { key: "˚", code: "KeyK", altKey: true, bubbles: true, cancelable: true })); return true; }');
    BrowserWorld::waitUntil($page, 'document.activeElement?.id === "monitoring-search-input"');
    $inner->locator('#monitoring-search-input')->press('Escape');
    BrowserWorld::waitUntil($page, 'document.activeElement?.getAttribute("aria-label") === "Search monitorings"');
    $inner->locator('body')->press('Alt+k');
    BrowserWorld::waitUntil($page, 'document.activeElement?.id === "monitoring-search-input"');
    $inner->locator('#monitoring-search-input')->fill('Keyboard');
    $inner->locator('#monitoring-search-results [role="option"]')->first()->waitFor();
    $inner->locator('#monitoring-search-input')->press('ArrowDown');
    $page->assertScript('document.getElementById("monitoring-search-input").getAttribute("aria-activedescendant")', 'monitoring-search-result-1');
    $inner->locator('#monitoring-search-input')->press('Enter');
    BrowserWorld::waitForPath($page, '/monitorings/' . $second->id);
});

it('offers search on mobile with empty feedback and no horizontal overflow', function (): void {
    $user = BrowserWorld::user();
    $page = BrowserWorld::signedInAt($user, '/dashboard');
    $page->resize(390, 844);
    $inner = $page->page();
    $inner->locator('header button[aria-label="Search monitorings"]')->click();
    BrowserWorld::waitUntil($page, 'document.activeElement?.id === "monitoring-search-input"');
    $inner->locator('#monitoring-search-input')->fill('No such service');
    BrowserWorld::waitForText($page, 'No matching monitorings');
    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
    $page->assertNoJavaScriptErrors();
});

it('ignores superseded responses and shows recoverable search errors', function (): void {
    $user = BrowserWorld::user();
    $page = BrowserWorld::signedInAt($user, '/dashboard');
    $inner = $page->page();
    $page->script(<<<'JS'
        function() {
            const nativeFetch = window.fetch.bind(window);
            window.__oldSearchStarted = false;
            window.__oldSearchFinished = false;
            window.fetch = (input, init) => {
                if (!String(input).startsWith('/api/monitorings/search?')) return nativeFetch(input, init);
                const search = new URL(String(input), location.origin).searchParams.get('search');
                if (search === 'Failure') return Promise.reject(new Error('Temporary search failure'));
                if (search === 'Old') window.__oldSearchStarted = true;
                return new Promise(resolve => setTimeout(() => {
                    if (search === 'Old') window.__oldSearchFinished = true;
                    resolve(new Response(JSON.stringify({data: [{id: search, name: search === 'Old' ? 'Outdated match' : 'Fresh match', target: 'https://example.test'}]}), {status: 200, headers: {'Content-Type': 'application/json'}}));
                }, search === 'Old' ? 1000 : 10));
            };
            return true;
        }
        JS);
    $inner->locator('body')->press('Alt+k');
    BrowserWorld::waitUntil($page, 'document.activeElement?.id === "monitoring-search-input"');
    $inner->locator('#monitoring-search-input')->fill('Old');
    BrowserWorld::waitUntil($page, 'window.__oldSearchStarted === true');
    $inner->locator('#monitoring-search-input')->fill('New');
    BrowserWorld::waitForText($page, 'Fresh match');
    BrowserWorld::waitUntil($page, 'window.__oldSearchFinished === true');
    $page->assertDontSee('Outdated match');
    $inner->locator('#monitoring-search-input')->fill('Failure');
    BrowserWorld::waitForText($page, 'Search could not be loaded. Try again.');
    $inner->locator('#monitoring-search-input')->fill('New');
    BrowserWorld::waitForText($page, 'Fresh match');
    $page->assertNoJavaScriptErrors();
});
