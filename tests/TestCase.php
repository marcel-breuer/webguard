<?php

declare(strict_types=1);

namespace Tests;

require_once __DIR__ . '/Support/PubliclyRoutableUrlDnsStub.php';

use App\Http\Middleware\PreventRequestForgeryExceptBearer;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgeryExceptBearer::class);

        config([
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
        ]);

        Cache::setDefaultDriver('array');
        $this->app->forgetInstance(RateLimiter::class);
    }

    protected function validCaptchaValue(): string
    {
        $value = 'human-check';
        $key = Hash::make($value);

        Cache::put('captcha_' . md5($key), true, 60);
        $this->withSession([
            'captcha' => [
                'sensitive' => false,
                'key' => $key,
                'encrypt' => false,
            ],
        ]);

        return $value;
    }
}
