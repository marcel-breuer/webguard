<?php

declare(strict_types=1);

use Tests\Support\BrowserWorld;

it('signs a user in and lands on the dashboard', function (): void {
    $user = BrowserWorld::user(['name' => 'Ada Browser']);

    $page = BrowserWorld::open('/login');
    $page->assertSee('Welcome to WebGuard');
    BrowserWorld::signIn($page, $user->email);

    BrowserWorld::waitForPath($page, '/dashboard');
    BrowserWorld::waitForText($page, 'Welcome back, Ada Browser');
});

it('keeps a user on the login page and explains rejected credentials', function (): void {
    $user = BrowserWorld::user();

    $page = BrowserWorld::open('/login');
    BrowserWorld::signIn($page, $user->email, 'not-the-password');

    BrowserWorld::waitForText($page, 'These credentials do not match our records.');
    $page->assertPathIs('/login');
});

it('sends signed-out visitors from the app to the login page', function (): void {
    $page = BrowserWorld::open('/dashboard');

    BrowserWorld::waitForPath($page, '/login');
    $page->assertSee('Welcome to WebGuard');
});
