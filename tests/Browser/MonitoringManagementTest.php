<?php

declare(strict_types=1);

use App\Enums\MonitoringType;
use App\Models\Monitoring;
use Tests\Support\BrowserWorld;

it('creates an HTTP monitoring through the form', function (): void {
    $user = BrowserWorld::user();

    $page = BrowserWorld::signedInAt($user, '/monitorings');
    $inner = $page->page();

    $inner->getByRole('button', ['name' => 'Create'])->first()->click();
    $dialog = $inner->locator('[role="dialog"]');
    $dialog->locator('input[name="name"]')->fill('Marketing site');
    $dialog->locator('input[name="target"]')->fill('https://marketing.example.test');
    $dialog->locator('form button[type="submit"]')->click();

    BrowserWorld::waitForText($page, 'Marketing site');

    $monitoring = Monitoring::query()->where('user_id', $user->id)->firstOrFail();
    expect($monitoring->name)->toBe('Marketing site')
        ->and($monitoring->target)->toBe('https://marketing.example.test')
        ->and($monitoring->type->value)->toBe('http');
});

it('shows validation feedback for an invalid monitoring target', function (): void {
    $user = BrowserWorld::user();

    $page = BrowserWorld::signedInAt($user, '/monitorings');
    $inner = $page->page();

    $inner->getByRole('button', ['name' => 'Create'])->first()->click();
    $dialog = $inner->locator('[role="dialog"]');
    $dialog->locator('input[name="name"]')->fill('Broken target');
    $dialog->locator('input[name="target"]')->fill('not a url');
    $dialog->locator('form button[type="submit"]')->click();

    $inner->locator('[role="dialog"] [role="alert"]')->first()->waitFor();
    expect(Monitoring::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('edits and then deletes a monitoring from its details page', function (): void {
    $user = BrowserWorld::user();
    $monitoring = Monitoring::factory()->create([
        'user_id' => $user->id,
        'type' => MonitoringType::HTTP,
        'name' => 'Edit me',
        'target' => 'https://edit.example.test',
    ]);
    $openActions = 'function() { document.querySelector("summary[aria-label=\'Monitoring actions\']").parentElement.open = true; return true; }';

    $page = BrowserWorld::signedInAt($user, '/monitorings/' . $monitoring->id);
    $inner = $page->page();
    BrowserWorld::waitForText($page, 'Edit me');

    $page->script($openActions);
    $inner->getByRole('button', ['name' => 'Edit monitoring'])->click();
    $dialog = $inner->locator('[role="dialog"]');
    $dialog->locator('input[name="name"]')->fill('Edited name');
    $dialog->getByRole('button', ['name' => 'Update'])->click();

    BrowserWorld::waitForText($page, 'Edited name');
    expect($monitoring->fresh()->name)->toBe('Edited name');

    $page->script('function() { window.confirm = () => true; return true; }');
    $page->script($openActions);
    $inner->getByRole('button', ['name' => 'Delete monitoring'])->click();

    BrowserWorld::waitForPath($page, '/monitorings');
    expect(Monitoring::query()->whereKey($monitoring->id)->exists())->toBeFalse();
});
