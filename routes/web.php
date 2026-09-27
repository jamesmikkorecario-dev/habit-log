<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});
