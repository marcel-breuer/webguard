<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BrowserWorld;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Browser');

// Shared CI runners render the built SvelteKit app slowly; the default 5s is too tight.
pest()->browser()->timeout(20_000);

pest()->beforeEach(function (): void {
    BrowserWorld::prepare();
})->in('Browser');
