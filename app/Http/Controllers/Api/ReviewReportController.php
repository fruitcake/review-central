<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Services\ReviewFiling;
use App\Services\ReviewPublisher;
use App\Services\RunTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewReportController extends Controller
{
    /**
     * Closes the record the precheck opened, with what the review produced.
     *
     * The report step sends `ai-review.json` here after publishing it to
     * Bitbucket, so the server holds the same result the pull request shows. A run with
     * no record — the server was unreachable when it started, say — is recorded anyway,
     * so nothing is lost just because the opening call failed.
     *
     * The repository comes from the run's token, never from the request.
     */
    public function __invoke(Request $request, RunTokens $tokens, ReviewFiling $filing): JsonResponse
    {
        $repository = $request->attributes->get('repository');

        if (! $repository instanceof Repository) {
            abort(403, 'This endpoint needs the token of a run.');
        }

        $validated = $request->validate([
            'branch' => ['required', 'string', 'max:255'],
            // max, not size: a run that reports a short sha is still reporting
            // about a real commit, and refusing it loses the whole result.
            'commit' => ['required', 'string', 'max:40'],
            'build' => ['nullable', 'integer'],
            'pull_request_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:finished,failed,timeout'],
            'result' => ['nullable', 'string', 'max:20'],
            'summary' => ['nullable', 'string'],
            'findings' => ['nullable', 'array'],
            // Decides whether the findings go up as inline comments or only as Code
            // Insights annotations. The run knows; the server would otherwise have
            // to guess, and guessing `both` would double-post in report mode.
            'mode' => ['nullable', 'string', 'in:comment,report,both'],
            // Whether this run wants its HIGH findings as pull request tasks. The
            // server's own switch can still refuse; neither can turn the other on.
            'create_tasks' => ['nullable', 'boolean'],
            // The threshold the run blocked on, so the server gates tasks on the
            // same rule rather than a second opinion about what counts: one for every
            // category, then any per-category ones (`medium,security:any`).
            'fail_on' => ['nullable', 'string', 'max:200', 'regex:'.ReviewPublisher::FAIL_ON_PATTERN],
            // False keeps the review off the pull request, for a
            // benchmark or a test of the review itself. The run is still recorded.
            'publish' => ['nullable', 'boolean'],
            // Which reviewer ran, read after the run, so it beats what the precheck
            // said. A field left out keeps the precheck's value.
            'agent' => ['nullable', 'string', 'max:32'],
            'provider' => ['nullable', 'string', 'max:64'],
            'effort' => ['nullable', 'string', 'max:20'],
            'advisor' => ['nullable', 'string', 'max:255'],
            'custom' => ['nullable', 'boolean'],
            // What the review wants read before the change ships, for the pull
            // request's own description: what it changed, and what has to happen
            // around it. Lists of plain lines; anything else is dropped. No length
            // rule: the publisher shortens a long line, and refusing one here would
            // lose the whole review over a line of its description.
            'changes' => ['nullable', 'array'],
            'changes.*' => ['string'],
            'before_release' => ['nullable', 'array'],
            'before_release.*' => ['string'],
            // Absent keeps what the last review left for a person; empty clears it.
            'manual_review' => ['nullable', 'array'],
            'manual_review.*' => ['string'],
            'cost' => ['nullable', 'numeric'],
            // Seconds, measured by the step. Only used when the server never saw the
            // run start and so has nothing to subtract from.
            'duration' => ['nullable', 'integer', 'min:0'],
            'model' => ['nullable', 'string', 'max:255'],
            // Per model, then per token type: {"claude-opus-5": {"input": 1, "output": 2}}
            // Only the types the server counts are checked; anything else is dropped by
            // recordUsage(), and must not cost the review a 422.
            'usage' => ['nullable', 'array'],
            'usage.*' => ['array'],
            'usage.*.input' => ['nullable', 'integer', 'min:0'],
            'usage.*.output' => ['nullable', 'integer', 'min:0'],
            'usage.*.cache_read' => ['nullable', 'integer', 'min:0'],
            'usage.*.cache_creation' => ['nullable', 'integer', 'min:0'],
            // What the OCR collector called cache_creation before 1.0.
            'usage.*.cache_write' => ['nullable', 'integer', 'min:0'],
        ]);

        // A report marks its commit reviewed, which the precheck then skips, and is
        // published on its pull request, which it may approve. So the run has to be
        // one for the branch and commit it reports about, and the pull request has
        // to come from that branch.
        $tokens->authorize($request, $repository, $validated['branch'], $validated['commit'], isset($validated['build']) ? (string) $validated['build'] : null);

        if (isset($validated['pull_request_id'])) {
            $this->vouchForPullRequest($repository, $validated['branch'], (string) $validated['pull_request_id']);
        }

        return response()->json($filing->file($repository, $validated));
    }

    /**
     * Refuses a report on a pull request that does not come from the branch the
     * run was started for: it would publish on, and may approve, a pull request
     * the run never reviewed.
     */
    protected function vouchForPullRequest(Repository $repository, string $branch, string $id): void
    {
        $pullRequest = $repository->sourceControl()->getPullRequest($id);
        $source = $pullRequest['source']['branch']['name'] ?? null;

        abort_unless(
            $source === $branch,
            403,
            $source === null ? "Pull request #{$id} could not be read." : "Pull request #{$id} comes from {$source}, not {$branch}.",
        );
    }
}
