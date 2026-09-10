<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class InternalUiAuthWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_guest_can_load_options_sign_in_and_register(): void
    {
        Notification::fake();
        $member = User::factory()->create([
            'email' => 'member@example.test',
            'password' => Hash::make('correct-password'),
        ]);
        $demo = User::factory()->create([
            'role' => UserRole::DEMO,
            'email' => 'demo@example.test',
        ]);

        $this->getJson(route('auth.options'))
            ->assertOk()
            ->assertJsonPath('data.captcha_url', url('captcha/register'))
            ->assertJsonPath('data.imprint_url', config('app.marketing_url') . '/imprint')
            ->assertJsonPath('data.terms_url', config('app.marketing_url') . '/terms-of-use');
        $this->getJson(route('auth.demo-credentials'))
            ->assertOk()
            ->assertJsonPath('data.email', $demo->email);
        $this->postJson(route('auth.login'), [
            'email' => $member->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('data.next_url', '/dashboard');

        $this->assertAuthenticatedAs($member);
        auth()->logout();

        $this->postJson(route('auth.register'), [
            'name' => 'New member',
            'email' => 'new-member@example.test',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
            'terms' => true,
            'captcha' => $this->validCaptchaValue(),
        ])->assertCreated()->assertJsonPath('data.next_url', '/verify-email');

        $model = User::query()->where('email', 'new-member@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($model);
        Notification::assertSentTo($model, VerifyEmail::class);
    }

    public function test_guest_can_request_and_complete_a_password_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $this->postJson(route('auth.password.email'), ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.message', __('password.reset_request_accepted'));
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::broker()->createToken($user);
        $this->postJson(route('auth.password.reset'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()->assertJsonPath('data.next_url', '/login');

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_password_reset_does_not_reveal_whether_an_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $testResponse = $this->postJson(route('auth.password.email'), ['email' => $user->email]);
        $unknownEmailResponse = $this->postJson(route('auth.password.email'), ['email' => 'unknown@example.test']);

        $this->assertSame($testResponse->status(), $unknownEmailResponse->status());
        $this->assertSame($testResponse->json(), $unknownEmailResponse->json());
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_requests_are_rate_limited(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('auth.password.email'), [
                'email' => 'unknown-' . $attempt . '@example.test',
            ])->assertOk();
        }

        $this->postJson(route('auth.password.email'), ['email' => 'unknown-final@example.test'])
            ->assertTooManyRequests();
    }

    public function test_authenticated_user_can_resend_verification_and_confirm_password(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['password' => Hash::make('correct-password')]);

        $this->actingAs($user)
            ->postJson(route('auth.verification.send'))
            ->assertOk()
            ->assertJsonPath('data.verification_required', true);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->actingAs($user)
            ->postJson(route('auth.password.confirm'), ['password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
        $this->actingAs($user)
            ->postJson(route('auth.password.confirm'), ['password' => 'correct-password'])
            ->assertOk()
            ->assertJsonPath('data.next_url', '/dashboard');

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }
}
