<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::livewire('people', 'pages::people.index')->name('people.index');
    Route::livewire('people/{user:username}', 'pages::people.show')->name('people.show');
    Route::livewire('people/{user:username}/habits/{habit}', 'pages::people.habit')->whereNumber('habit')->name('people.habit');
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
    Route::view('settings/security', 'pages.settings.security')->name('settings.security');
});
