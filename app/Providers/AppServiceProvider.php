<?php

namespace App\Providers;

use App\Actions\Accounts\SessionSecurity;
use App\Auth\CredentialUrls;
use App\Auth\RevocableDatabaseSessionHandler;
use App\Mail\SecureMailManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->extend('mail.manager', fn ($manager, $app) => new SecureMailManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        ResetPassword::createUrlUsing(fn ($user, $token) => CredentialUrls::generator()->route('password.reset', [
            'token' => $token, 'email' => $user->getEmailForPasswordReset(),
        ]));
        Session::extend('database', fn ($app) => new RevocableDatabaseSessionHandler(
            $app['db']->connection(config('session.connection')), config('session.table'), config('session.lifetime'), $app,
        ));
        Event::listen(Login::class, [SessionSecurity::class, 'login']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
