<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Enums\SupportedLanguage;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Support\LegalLinks;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

final class AuthWorkspaceController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'captcha_url' => url('captcha/register'),
            'imprint_url' => LegalLinks::imprint(),
            'terms_url' => LegalLinks::termsOfUse(),
            'privacy_url' => LegalLinks::privacyPolicy(),
        ]]);
    }

    public function login(LoginRequest $loginRequest): JsonResponse
    {
        $loginRequest->authenticate();
        $loginRequest->session()->regenerate();

        $userLocale = Auth::user()?->locale;
        if (! SupportedLanguage::isSupported($userLocale)) {
            $userLocale = SupportedLanguage::default()->value;
        }

        return response()
            ->json(['data' => ['next_url' => $this->intendedPath($loginRequest)]])
            ->withCookie(cookie(
                SupportedLanguage::cookieName(),
                $userLocale,
                SupportedLanguage::cookieDurationMinutes(),
            ));
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'terms' => ['accepted'],
            'captcha' => ['required', 'captcha'],
        ], [
            'captcha.required' => __('auth.register.captcha_required'),
            'captcha.captcha' => __('auth.register.captcha_invalid'),
        ]);

        $model = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => UserRole::REGULAR,
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);

        event(new Registered($model));
        Auth::login($model);
        $request->session()->regenerate();

        return response()->json(['data' => ['next_url' => '/verify-email']], 201);
    }

    public function demoCredentials(): JsonResponse
    {
        $demoUser = User::query()->where('role', UserRole::DEMO)->first();

        if (! $demoUser instanceof User) {
            return response()->json(['message' => __('auth.guest_login.no_guest_user_found')], 404);
        }

        return response()->json(['data' => ['email' => $demoUser->email]]);
    }

    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => $validated['email']]);

        return response()->json(['data' => ['message' => __('password.reset_request_accepted')]]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            [
                'token' => $validated['token'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->string('password_confirmation')->toString(),
            ],
            static function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['data' => ['next_url' => '/login', 'message' => __($status)]]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['verification_required' => false, 'next_url' => '/dashboard']]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['data' => ['verification_required' => true, 'message' => __('auth.verify_email.link_sent')]]);
    }

    public function confirmPassword(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        /** @var User $user */
        $user = $request->user();

        if (! Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => $request->string('password')->toString(),
        ])) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        $request->session()->put('auth.password_confirmed_at', Date::now()->getTimestamp());

        return response()->json(['data' => ['next_url' => $this->intendedPath($request)]]);
    }

    private function intendedPath(Request $request): string
    {
        $intended = $request->session()->pull('url.intended', '/dashboard');
        $parts = parse_url(is_string($intended) ? $intended : '/dashboard');

        if ($parts === false || isset($parts['host'])) {
            return '/dashboard';
        }

        $path = $parts['path'] ?? '/dashboard';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return Str::startsWith($path, '/') ? $path . $query : '/dashboard';
    }
}
