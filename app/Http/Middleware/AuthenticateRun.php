<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Repository;
use App\Services\RunTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a step of a review this server started by the token of its run,
 * from the `X-Api-Key` header, and binds the run's repository to the request as
 * `repository`. Nothing else may call the review endpoints: a token is issued for
 * one run and dies with it.
 */
class AuthenticateRun
{
    public function __construct(protected RunTokens $runTokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key');

        // A header sent twice arrives as an array, which is simply no key.
        if (! is_string($key) || $key === '') {
            abort(403);
        }

        $runRepository = $this->runTokens->repositoryId($key);

        $repository = $runRepository === null
            ? null
            : Repository::query()->whereKey($runRepository)->with(['tenant', 'integration'])->first();

        if ($repository === null) {
            abort(403);
        }

        $request->attributes->set('repository', $repository);

        return $next($request);
    }
}
