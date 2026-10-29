<?php

use App\Http\Controllers\Settings\AccountDeletionController;
use App\Http\Controllers\Settings\EmailChangeController;
use App\Http\Controllers\Settings\SessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('/account-deleted', 'pages.settings.account-deleted')->middleware('guest')->name('account.deleted');

Route::middleware('auth')->group(function () {
    // Persistent Livewire route binding runs before component hydration. If an
    // owner was deleted, leave the old shared content instead of a 404 overlay.
    $missingSharedOwner = fn (Request $request) => $request->hasHeader('X-Livewire')
        ? redirect()->route('people.index') : abort(404);
    Route::livewire('people', 'pages::people.index')->name('people.index');
    Route::livewire('people/{user:username}', 'pages::people.show')->missing($missingSharedOwner)->name('people.show');
    Route::livewire('people/{user:username}/habits/{habit}', 'pages::people.habit')->whereNumber('habit')->missing($missingSharedOwner)->name('people.habit');
    Route::livewire('journal', 'pages::journal.edit')->name('journal.index');
    Route::livewire('tracking', 'pages::tracking.index')->name('tracking.index');
    Route::livewire('habits/{habit}/entries', 'pages::tracking.history')->whereNumber('habit')->name('entries.index');
    Route::livewire('habits/{habit}/entries/{date}', 'pages::tracking.edit')->whereNumber('habit')->name('entries.edit');
    Route::livewire('categories', 'pages::categories.index')->name('categories.index');
    Route::livewire('habits', 'pages::habits.index')->name('habits.index');
    Route::livewire('habits/create', 'pages::habits.edit')->name('habits.create');
    Route::livewire('habits/{habit}/edit', 'pages::habits.edit')->whereNumber('habit')->name('habits.edit');
    Route::livewire('habits/{habit}', 'pages::habits.show')->whereNumber('habit')->name('habits.show');
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('settings/profile', 'pages::settings.profile')->name('settings.profile');
    Route::get('settings/security', [EmailChangeController::class, 'settings'])->name('settings.security');
    Route::delete('settings/security/account', [AccountDeletionController::class, 'destroy'])->middleware('throttle:account-delete')->name('settings.account.destroy');
    Route::post('settings/security/sessions/revoke', [SessionController::class, 'destroy'])->middleware('throttle:session-revoke')->name('settings.sessions.revoke');
    Route::controller(EmailChangeController::class)->prefix('settings/security/email')->name('settings.email.')->group(function () {
        Route::post('/', 'store')->middleware(['throttle:email-change', 'throttle:email-delivery'])->name('request');
        Route::post('/resend', 'store')->middleware(['throttle:email-change', 'throttle:email-delivery'])->name('resend');
        Route::post('/cancel', 'cancel')->middleware('throttle:email-change')->name('cancel');
        Route::get('/verify', 'verify')->name('verify');
        Route::post('/verify', 'confirm')->middleware('throttle:email-change')->name('confirm');
    });
});
