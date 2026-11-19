<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A review's suggested replacement for a line of code. The host turns it
        // into an Apply button that commits it verbatim, so its leading indentation
        // is part of the value: trimming it would commit the line unindented.
        $middleware->trimStrings(except: ['findings.*.suggestion']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The repository's host refused or failed a call made for a pipeline. Said
        // as that, so the build log points at the connection and not at this server.
        $exceptions->render(function (RequestException $exception, Request $request) {
            if (! $request->is('api/review/*')) {
                return null;
            }

            return response()->json([
                'message' => "The repository's host answered {$exception->response->status()}. Check the credentials of the repository's connection.",
            ], 502);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
