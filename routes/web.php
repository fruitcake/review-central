<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\ConnectionOAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PersonalConnectionController;
use App\Http\Controllers\RateReviewController;
use App\Http\Controllers\SingleSignOnController;
use App\Http\Controllers\TicketAttachmentController;
use App\Services\PersonalConnections;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/invitation/{user}', AcceptInvitationController::class)->middleware(['signed', 'throttle:20,1'])->name('invitation.accept');

// The code mailed to the address of a Bitbucket or GitHub account that is not linked yet.
Route::get('/sign-in/email-code', [SingleSignOnController::class, 'code'])->name('sign-in.code');
Route::post('/sign-in/email-code', [SingleSignOnController::class, 'checkCode'])->middleware('throttle:10,1')->name('sign-in.code.check');

// Signing in with an account somewhere else. Guests only by nature: the answer signs in.
Route::get('/sign-in/{provider}', [SingleSignOnController::class, 'redirect'])->middleware('throttle:20,1')->name('sign-in.redirect');
Route::get('/sign-in/{provider}/callback', [SingleSignOnController::class, 'callback'])->middleware('throttle:20,1')->name('sign-in.callback');

// A connection that gets its access by signing in at the service. The answer
// names no connection: the session that asked knows which one it was for.
Route::get('/connections/oauth/callback', [ConnectionOAuthController::class, 'callback'])->middleware('throttle:20,1')->name('connections.oauth.callback');
Route::get('/connections/{connection}/oauth', [ConnectionOAuthController::class, 'redirect'])->whereNumber('connection')->middleware('throttle:20,1')->name('connections.oauth.redirect');

// A user's own Bitbucket or GitHub account, connected on their profile to write
// as them. Its callback is the one the Bitbucket consumer or GitHub App has for
// people, which signing in with it comes back through as well, as a guest. A
// session from before a password change no longer counts, as in the panels.
Route::get('/connections/{host}', [PersonalConnectionController::class, 'redirect'])->whereIn('host', array_keys(PersonalConnections::HOSTS))->middleware(['auth', 'auth.session', 'throttle:20,1'])->name('connections.personal.redirect');
Route::get('/connections/{host}/callback', [PersonalConnectionController::class, 'callback'])->whereIn('host', array_keys(PersonalConnections::HOSTS))->middleware(['auth.session', 'throttle:20,1'])->name('connections.personal.callback');

// A ticket's file, by an address ticket-attachment signed for one user and a short while.
Route::get('/tickets/attachment', TicketAttachmentController::class)->middleware(['signed', 'throttle:30,1'])->name('tickets.attachment');

Route::get('/rate/{token}', [RateReviewController::class, 'show'])->name('reviews.rate');
Route::post('/rate/{token}', [RateReviewController::class, 'store'])->middleware('throttle:20,1')->name('reviews.rate.store');
