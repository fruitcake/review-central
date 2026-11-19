<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Services\ReviewOpening;
use App\Services\RunTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewContextController extends Controller
{
    /**
     * Everything a review needs for one pull request: whether to run at all, and
     * the pull request with its comments in a compact shape.
     *
     * The repository comes from the run's token, never from the request.
     */
    public function __invoke(Request $request, ReviewOpening $opening, RunTokens $tokens): JsonResponse
    {
        $repository = $request->attributes->get('repository');

        if (! $repository instanceof Repository) {
            abort(403, 'This endpoint needs the token of a run.');
        }

        // A POST with a body, not a GET with nine query parameters: this call takes
        // the review lock and opens the run's record, so it is not a read — and a
        // branch name is free text that has no business being spliced into a URL.
        $input = $request->validate([
            'pull_request_id' => ['nullable', 'integer'],
            'branch' => ['nullable', 'string', 'max:255'],
            'commit' => ['nullable', 'string', 'max:40'],
            'lock' => ['nullable', 'string', 'max:255'],
            // Separate from the lock: a comparison run takes no lock but is still a
            // run of its own, and its record has to say which build it was.
            'build' => ['nullable', 'string', 'max:255'],
            'recipe' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'string', 'max:20'],
            'fail_on' => ['nullable', 'string', 'max:200'],
            'model' => ['nullable', 'string', 'max:255'],
            'create_tasks' => ['nullable', 'boolean'],
            'drafts' => ['nullable', 'boolean'],
            'probe' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
            // Which reviewer runs. Recorded on the run only; the report sends them
            // again after the run, and those win.
            'agent' => ['nullable', 'string', 'max:32'],
            'provider' => ['nullable', 'string', 'max:64'],
            'effort' => ['nullable', 'string', 'max:20'],
            'advisor' => ['nullable', 'string', 'max:255'],
            'custom' => ['nullable', 'boolean'],
            // The skill contract the run reads instructions for. Without it the
            // context carries none.
            'instructions' => ['nullable', 'string', 'max:16'],
            // What the repository is built on, which decides the stack guides. Sent
            // by a precheck that detects it from a clone; one that runs without a
            // clone sends REVIEW_STACK as it is set, and the server decides.
            'stack' => ['nullable', 'array'],
            'stack.*' => ['string', 'max:32'],
            'review_stack' => ['nullable', 'string', 'max:255'],
            // A custom run trying the drafts, or the published ones, whatever the
            // repository reads.
        ]);

        $opened = $opening->open(
            $repository,
            $input,
            // The lock and the run's record are keyed on the pull request's branch and the
            // commit, so the run has to be one for those, not for another pull request's.
            function (string $branch, ?string $commit) use ($request, $repository, $tokens, $input): void {
                // The lock it asks to take and the record it asks to open are both
                // named by a build, and each has to be the run's own.
                $builds = array_filter([$input['lock'] ?? null, $input['build'] ?? null], filled(...)) ?: [null];

                foreach ($builds as $build) {
                    $tokens->authorize($request, $repository, $branch, $commit, $build === null ? null : (string) $build);
                }
            },
        );

        return response()->json($opened['body'], $opened['status']);
    }
}
