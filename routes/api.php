<?php

use App\Http\Controllers\Api\GitHubAppWebhookController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Before the connection's own: "github-app" is never a connection's key, which is 32 letters and digits.
Route::post('/webhooks/github-app', GitHubAppWebhookController::class)->name('api.webhooks.github-app');
Route::post('/webhooks/{key}', WebhookController::class)->name('api.webhooks');
