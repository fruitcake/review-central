<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Connections\SourceControlDriver;
use App\Enums\ConnectionKind;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Repository;
use App\Services\ReviewRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * Takes a source control connection's webhook, to start the review for what a
     * push does not cover: a `/review` comment, and a draft marked ready. Signed
     * with the connection's own secret.
     */
    public function __invoke(Request $request, string $key, ReviewRequest $reviewRequest): JsonResponse
    {
        $connection = Connection::query()
            ->where('webhook_key', $key)
            ->where('kind', ConnectionKind::SourceControl)
            ->firstOrFail();

        /** @var SourceControlDriver $driver */
        $driver = $connection->driver();
        $event = $driver->webhookEvent($connection, $request);

        if ($event === null) {
            abort(403);
        }

        // Proof that the webhook is in place, for whoever checks a repository's setup.
        $connection->forceFill(['webhook_received_at' => now()])->save();

        $repository = Repository::query()
            ->where('connection_id', $connection->getKey())
            ->where('workspace', $event->workspace)
            ->where('slug', $event->slug)
            ->first();

        if ($repository !== null) {
            $repository->forceFill(['webhook_received_at' => now()])->save();
            $reviewRequest->handle($repository, $event);
        }

        return response()->json(['status' => 'ok']);
    }
}
