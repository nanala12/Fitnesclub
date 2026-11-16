<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Enums\UserRole;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            return (new Timebox)->call(function () use ($request): ?User {
                $user = User::where('email', mb_strtolower(trim($request->string('email')->toString())))->first();
                if (! $user || ! Hash::check($request->string('password')->toString(), $user->password) || $user->isSuspended()) {
                    return null;
                }
                if (Hash::needsRehash($user->password)) {
                    $user->forceFill(['password' => Hash::make($request->string('password')->toString())])->save();
                }

                return $user;
            }, 200000);
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request): Response => $this->passwordSetupView($request));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-forms', fn (Request $request) => Limit::perMinute(5)->by($request->route()?->getName().'|'.$request->ip()));
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $username = $request->input(Fortify::username());
            $throttleKey = Str::transliterate((is_string($username) ? Str::lower($username) : '').'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                (is_string($credentialId) && $credentialId !== '' ? $credentialId : $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }

    private function passwordSetupView(Request $request): Response
    {
        $email = is_string($request->query('email')) ? mb_strtolower(trim($request->query('email'))) : '';
        $token = is_string($request->route('token')) ? $request->route('token') : '';
        // Keep invalid-link content generic and use the existing authentication timebox.
        $invited = (new Timebox)->call(function () use ($email, $token): ?User {
            if ($email === '' || strlen($email) > 255 || $token === '') {
                return null;
            }
            $user = User::where('email', $email)->first();
            if ($user === null || ! $user->hasPendingStaffInvitation() || $user->isSuspended()
                || ! in_array($user->role, [UserRole::Employee, UserRole::Admin], true)
                || ! PasswordBroker::broker(config('fortify.passwords'))->tokenExists($user, $token)) {
                return null;
            }

            return $user;
        }, 200000);

        return Inertia::render($invited === null ? 'auth/ResetPassword' : 'auth/ActivateAccount', [
            'email' => $email, 'token' => $token,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ...($invited === null ? [] : ['isAdminInvitation' => $invited->role === UserRole::Admin,
                'panelLabel' => $invited->role === UserRole::Admin ? 'Panel administratora' : 'Panel pracownika']),
        ]);
    }
}
