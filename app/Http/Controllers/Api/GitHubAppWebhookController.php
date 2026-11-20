<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ConnectionKind;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\OpenPullRequest;
use App\Services\ReviewRequest;
use App\Services\WebhookDeliveries;
use App\SourceControl\GitHubApp;
use App\SourceControl\GitHubAppDriver;
use App\SourceControl\GitHubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The GitHub App's one webhook, for every organisation it is installed on:
 * signed with the app's secret, and taken by the connection that has the
 * installation it names. One for an installation no team connected starts
 * nothing.
 *
 * Besides what a connection's own webhook delivers, it brings the press of
 * "Approve and review" on a check that waits: that starts the review, when the
 * one who pressed it is one of the team's trusted accounts.
 */
class GitHubAppWebhookController extends Controller
{
    public function __invoke(Request $request, GitHubApp $app, WebhookDeliveries $deliveries, ReviewRequest $reviews): JsonResponse
    {
        if (! $app->signed($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            abort(403);
        }

        $installation = $request->json('installation.id');
        $connection = is_numeric($installation) ? Connection::query()
            ->where('kind', ConnectionKind::SourceControl)
            ->where('driver', 'github-app')
            ->get()
            ->first(fn (Connection $connection): bool => $connection->setting('installation_id') === (string) $installation) : null;

        if ($connection === null) {
            return response()->json(['status' => 'ignored']);
        }

        if ($request->header('X-GitHub-Event') === 'check_run' && $request->json('action') === 'requested_action') {
            $this->approve($request, $connection, $deliveries, $reviews);

            return response()->json(['status' => 'ok']);
        }

        /** @var GitHubAppDriver $driver */
        $driver = $connection->driver();
        $deliveries->deliver($connection, $driver->eventFrom($connection, $request));

        return response()->json(['status' => 'ok']);
    }

    /**
     * Starts the review a check waited for: of the open pull request whose head it
     * is on, which a fork's check does not name.
     */
    protected function approve(Request $request, Connection $connection, WebhookDeliveries $deliveries, ReviewRequest $reviews): void
    {
        $repository = $deliveries->repository($connection, (string) $request->json('repository.owner.login'), (string) $request->json('repository.name'));
        $sender = $request->json('sender');

        if ($repository === null || $request->json('requested_action.identifier') !== GitHubClient::APPROVE_ACTION || ! is_array($sender)
            || ! $repository->tenant->trusts(is_numeric($sender['id'] ?? null) ? (string) $sender['id'] : null, is_string($sender['login'] ?? null) ? $sender['login'] : null)) {
            return;
        }

        $pullRequest = OpenPullRequest::query()
            ->where('repository_id', $repository->getKey())
            ->where('commit', (string) $request->json('check_run.head_sha'))
            ->first();

        if ($pullRequest?->branch === null) {
            return;
        }

        try {
            $reviews->start($repository, $pullRequest->branch);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
