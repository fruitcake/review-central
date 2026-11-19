<?php

use App\Http\Controllers\Api\ReviewContextController;
use App\Http\Controllers\Api\ReviewReportController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\AuthenticateRun;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/{key}', WebhookController::class)->name('api.webhooks');

// What the steps of a review call, each with the token of its run.
Route::middleware(AuthenticateRun::class)->prefix('review')->name('api.review.')->group(function () {
    Route::post('/review-context', ReviewContextController::class)->name('context');
    Route::post('/review-report', ReviewReportController::class)->name('report');
});
