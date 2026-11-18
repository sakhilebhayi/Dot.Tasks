<?php

use Dot\SsoClient\Http\Controllers\BackchannelLogoutController;
use Dot\SsoClient\Http\Controllers\ClaimController;
use Dot\SsoClient\Http\Controllers\SsoController;
use Dot\SsoClient\Http\Middleware\RequireEnabled;
use Illuminate\Support\Facades\Route;

// Every route answers 404 while DOTSSO_ENABLED is false (the claim pages below have one narrow exception).
Route::middleware(['web', RequireEnabled::class])->group(function () {
    Route::get('/sso/login', [SsoController::class, 'login'])->middleware('throttle:60,1')->name('dotsso.login');
    Route::get('/sso/callback', [SsoController::class, 'callback'])->middleware('throttle:60,1')->name('dotsso.callback');
    // POST only: a link someone else can plant must not be able to sign a person out of everything.
    Route::post('/sso/logout', [SsoController::class, 'logout'])->name('dotsso.logout');

});

// The claim pages: enabled normally, and also for a claim already in the session while the flag is off (the platform's
// fallback receiver can start one).
Route::middleware(['web', RequireEnabled::class.':claim'])->group(function () {
    Route::get('/sso/claim', [ClaimController::class, 'show'])->name('dotsso.claim');
    Route::post('/sso/claim', [ClaimController::class, 'verify'])->middleware('throttle:20,1')->name('dotsso.claim.verify');
    Route::post('/sso/claim/resend', [ClaimController::class, 'resend'])->middleware('throttle:6,1')->name('dotsso.claim.resend');
    Route::post('/sso/claim/cancel', [ClaimController::class, 'cancel'])->name('dotsso.claim.cancel');
});

// Server to server from the identity provider (OIDC Back-Channel Logout 1.0): no session and no CSRF token. Its
// authenticity is the signed logout token, which the controller verifies.
Route::post('/sso/backchannel-logout', BackchannelLogoutController::class)
    ->middleware(['throttle:120,1', RequireEnabled::class])
    ->name('dotsso.backchannel');
