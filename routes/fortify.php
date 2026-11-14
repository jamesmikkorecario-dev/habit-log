<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers as Fortify;
use Laravel\Fortify\RoutePath;

// The application's enabled Fortify routes. Keep parity with the package's
// routes when upgrading Fortify or enabling features; only registration email
// verification differs, using a relative signature on the canonical host.
Route::group(['middleware' => config('fortify.middleware', ['web'])], function () {
    $views = config('fortify.views', true);
    $auth = config('fortify.auth_middleware', 'auth').':'.config('fortify.guard');
    $guest = 'guest:'.config('fortify.guard');
    $loginLimiter = config('fortify.limiters.login');
    $verificationLimiter = config('fortify.limiters.verification', '6,1');

    if ($views) {
        Route::get(RoutePath::for('login', '/login'), [Fortify\AuthenticatedSessionController::class, 'create'])
            ->middleware($guest)->name('login');
    }
    Route::post(RoutePath::for('login', '/login'), [Fortify\AuthenticatedSessionController::class, 'store'])
        ->middleware(array_filter([$guest, $loginLimiter ? 'throttle:'.$loginLimiter : null]))->name('login.store');
    Route::post(RoutePath::for('logout', '/logout'), [Fortify\AuthenticatedSessionController::class, 'destroy'])
        ->middleware($auth)->name('logout');

    if (Features::enabled(Features::resetPasswords())) {
        if ($views) {
            Route::get(RoutePath::for('password.request', '/forgot-password'), [Fortify\PasswordResetLinkController::class, 'create'])
                ->middleware($guest)->name('password.request');
            Route::get(RoutePath::for('password.reset', '/reset-password/{token}'), [Fortify\NewPasswordController::class, 'create'])
                ->middleware($guest)->name('password.reset');
        }
        Route::post(RoutePath::for('password.email', '/forgot-password'), [Fortify\PasswordResetLinkController::class, 'store'])
            ->middleware($guest)->name('password.email');
        Route::post(RoutePath::for('password.update', '/reset-password'), [Fortify\NewPasswordController::class, 'store'])
            ->middleware($guest)->name('password.update');
    }

    if (Features::enabled(Features::registration())) {
        if ($views) {
            Route::get(RoutePath::for('register', '/register'), [Fortify\RegisteredUserController::class, 'create'])
                ->middleware($guest)->name('register');
        }
        Route::post(RoutePath::for('register', '/register'), [Fortify\RegisteredUserController::class, 'store'])
            ->middleware($guest)->name('register.store');
    }

    if (Features::enabled(Features::emailVerification())) {
        if ($views) {
            Route::get(RoutePath::for('verification.notice', '/email/verify'), [Fortify\EmailVerificationPromptController::class, '__invoke'])
                ->middleware($auth)->name('verification.notice');
        }
        Route::get(RoutePath::for('verification.verify', '/email/verify/{id}/{hash}'), [Fortify\VerifyEmailController::class, '__invoke'])
            ->middleware([$auth, 'signed:relative', 'throttle:'.$verificationLimiter])->name('verification.verify');
        Route::post(RoutePath::for('verification.send', '/email/verification-notification'), [Fortify\EmailVerificationNotificationController::class, 'store'])
            ->middleware([$auth, 'throttle:'.$verificationLimiter])->name('verification.send');
    }

    if (Features::enabled(Features::updatePasswords())) {
        Route::put(RoutePath::for('user-password.update', '/user/password'), [Fortify\PasswordController::class, 'update'])
            ->middleware($auth)->name('user-password.update');
    }

    if ($views) {
        Route::get(RoutePath::for('password.confirm', '/user/confirm-password'), [Fortify\ConfirmablePasswordController::class, 'show'])
            ->middleware($auth)->name('password.confirm');
    }
    Route::get(RoutePath::for('password.confirmation', '/user/confirmed-password-status'), [Fortify\ConfirmedPasswordStatusController::class, 'show'])
        ->middleware($auth)->name('password.confirmation');
    Route::post(RoutePath::for('password.confirm', '/user/confirm-password'), [Fortify\ConfirmablePasswordController::class, 'store'])
        ->middleware($auth)->name('password.confirm.store');
});
