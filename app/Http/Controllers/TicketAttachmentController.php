<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ActionRefused;
use App\Models\User;
use App\Services\RepositoryAccess;
use App\Services\TicketLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * A ticket's file, for an agent to save in its checkout: an address that
 * ticket-attachment hands out, signed for a short while and for one user. The
 * user is checked again when it is opened, and the file is read from the
 * ticket's source then.
 */
class TicketAttachmentController extends Controller
{
    public const MINUTES = 15;

    public function __invoke(Request $request, RepositoryAccess $access, TicketLookup $tickets): Response
    {
        // From the signed address only: a body of the request is not signed, and would go before it.
        $signed = fn (string $key): string => is_string($value = $request->query($key)) ? $value : '';

        $user = User::query()->find((int) $signed('user')) ?? abort(403);
        $repository = $access->repositories($user)->whereKey((int) $signed('repository'))->first() ?? abort(404);

        try {
            $file = $tickets->attachment($repository, $signed('reference'), (int) $signed('number'));
        } catch (ActionRefused $exception) {
            abort(404, $exception->getMessage());
        }

        return response($file->contents, 200, [
            'Content-Type' => $file->mimeType,
            // Saved, never shown here: a file of anyone's making must not run on this site.
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', self::filename($file->name), self::filename(Str::ascii($file->name))),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A name the header takes: no path in it, and in the plain fallback no %.
     */
    protected static function filename(string $name): string
    {
        return trim(str_replace(['/', '\\', '%', '"'], '_', $name)) ?: 'attachment';
    }
}
