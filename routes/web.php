<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
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
});
