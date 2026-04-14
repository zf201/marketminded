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
        Route::livewire('intelligence', 'pages::teams.brand-intelligence')->name('brand.intelligence');
        Route::livewire('ai-log', 'pages::teams.ai-log')->name('ai.log');
        Route::livewire('create', 'pages::teams.create')->name('create');
        Route::livewire('create/{conversation}', 'pages::teams.create-chat')->name('create.chat');
        Route::livewire('topics', 'pages::teams.topics')->name('topics');
            Route::livewire('content/{contentPiece}', 'pages::teams.content-piece')->name('content.show');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
