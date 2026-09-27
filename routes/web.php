<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::livewire('categories', 'pages::categories.index')->name('categories.index');
    Route::livewire('habits', 'pages::habits.index')->name('habits.index');
    Route::livewire('habits/create', 'pages::habits.edit')->name('habits.create');
    Route::livewire('habits/{habit}/edit', 'pages::habits.edit')->whereNumber('habit')->name('habits.edit');
    Route::livewire('habits/{habit}', 'pages::habits.show')->whereNumber('habit')->name('habits.show');
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('settings/profile', 'pages::settings.profile')->name('settings.profile');
});
