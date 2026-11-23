<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\ConnectionOAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RateReviewController;
use App\Http\Controllers\SingleSignOnController;
use App\Http\Controllers\TicketAttachmentController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/invitation/{user}', AcceptInvitationController::class)->middleware(['signed', 'throttle:20,1'])->name('invitation.accept');

// Signing in with an account somewhere else. Guests only by nature: the answer signs in.
Route::get('/sign-in/{provider}', [SingleSignOnController::class, 'redirect'])->middleware('throttle:20,1')->name('sign-in.redirect');
Route::get('/sign-in/{provider}/callback', [SingleSignOnController::class, 'callback'])->middleware('throttle:20,1')->name('sign-in.callback');

// A connection that gets its access by signing in at the service. The answer
// names no connection: the session that asked knows which one it was for.
Route::get('/connections/oauth/callback', [ConnectionOAuthController::class, 'callback'])->middleware('throttle:20,1')->name('connections.oauth.callback');
Route::get('/connections/{connection}/oauth', [ConnectionOAuthController::class, 'redirect'])->whereNumber('connection')->middleware('throttle:20,1')->name('connections.oauth.redirect');

// A ticket's file, by an address ticket-attachment signed for one user and a short while.
Route::get('/tickets/attachment', TicketAttachmentController::class)->middleware(['signed', 'throttle:30,1'])->name('tickets.attachment');

Route::get('/rate/{token}', [RateReviewController::class, 'show'])->name('reviews.rate');
Route::post('/rate/{token}', [RateReviewController::class, 'store'])->middleware('throttle:20,1')->name('reviews.rate.store');
