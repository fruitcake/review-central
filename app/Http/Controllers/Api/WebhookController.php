<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Connections\SourceControlDriver;
use App\Enums\ConnectionKind;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\WebhookDeliveries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * Takes a source control connection's webhook, to start the review for what a
     * push does not cover: a `/review` comment, and a draft marked ready. Signed
     * with the connection's own secret.
     */
    public function __invoke(Request $request, string $key, WebhookDeliveries $deliveries): JsonResponse
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

        $deliveries->deliver($connection, $event);

        return response()->json(['status' => 'ok']);
    }
}
