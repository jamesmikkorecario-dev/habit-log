<?php

namespace App\Providers;

/* @chisel-registration */
use App\Actions\Fortify\CreateNewUser;
/* @end-chisel-registration */
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\PasswordUpdateResponse;
use App\Http\Responses\RecoveryLinkResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\Laravel\Fortify\Contracts\PasswordUpdateResponse::class, PasswordUpdateResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, RecoveryLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, RecoveryLinkResponse::class);
        $this->app->bind(FailedPasswordResetResponse::class, function ($app, array $parameters) {
            // A guessed reset URL must not disclose whether its email exists either.
            $status = $parameters['status'] === Password::INVALID_USER
                ? Password::INVALID_TOKEN : $parameters['status'];

            return new \Laravel\Fortify\Http\Responses\FailedPasswordResetResponse($status);
        });
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
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        /* @chisel-registration */
        Fortify::createUsersUsing(CreateNewUser::class);
        /* @end-chisel-registration */
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::loginView(fn () => view('pages::auth.login'));
        /* @chisel-registration */
        Fortify::registerView(fn () => view('pages::auth.register'));
        /* @end-chisel-registration */
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('public-auth', fn (Request $request) => Limit::perMinute(20)
            ->by($request->route()->getName().'|'.$request->ip()));

        RateLimiter::for('password-confirmation', fn (Request $request) => Limit::perMinute(5)
            ->by((string) $request->user()?->getAuthIdentifier()));

        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)
            ->by((string) $request->user()?->getAuthIdentifier()));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

    }
}
