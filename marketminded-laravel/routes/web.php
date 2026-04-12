<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::view('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::livewire('brand', 'pages::teams.brand-setup')->name('brand.setup');
        Route::livewire('intelligence', 'pages::teams.brand-intelligence')->name('brand.intelligence');
        Route::livewire('ai-operations', 'pages::teams.ai-operations')->name('ai.operations');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
