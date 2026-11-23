<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\ActionRefused;
use App\Exceptions\RepositoryNotFound;
use App\Http\Controllers\TicketAttachmentController;
use App\Services\TicketLookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\URL;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Reads a file attached to a ticket: an image (a screenshot, a design) is returned as an image to look at, a small text file (a log, CSV, JSON) as text. Any other file, such as a PDF, a spreadsheet or a large CSV to import, comes back as an address to download it from for 15 minutes, with the command to save it: save it in the checkout where the work needs it, rather than reading it into the conversation. A large text file also shows its first lines.

    The `ticket` tool lists a ticket's attachments under `attachments`, each with its `number`; pass the ticket's `reference` and that `number`. Read the ones that matter for the work, such as a screenshot of the bug or the design to build; there is no need to read them all. One that is not `readable` is kept elsewhere, such as a Google Drive link, and is not read here.
    TXT)]
class TicketAttachmentTool extends ReviewTool
{
    /** Images are scaled down to this longest side: larger costs tokens without showing more. */
    public const MAX_IMAGE_SIDE = 1568;

    /** The largest text returned as text; a larger file is downloaded. */
    public const MAX_TEXT_BYTES = 50 * 1024;

    /** The lines of a larger text file shown with its address. */
    protected const PREVIEW_LINES = 20;

    protected string $name = 'ticket-attachment';

    public function handle(Request $request, TicketLookup $tickets): Response|ResponseFactory
    {
        $validated = $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:100'],
            'number' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            $repository = $this->repository($request);
            $file = $tickets->attachment($repository, (string) $validated['reference'], (int) $validated['number']);
        } catch (RepositoryNotFound|ActionRefused $exception) {
            return Response::error($exception->getMessage());
        }

        if ($file->isImage()) {
            $image = $file->fitWithin(self::MAX_IMAGE_SIDE);

            return Response::make([Response::text($image->name), Response::image($image->contents, $image->mimeType)]);
        }

        if ($file->isText() && strlen($file->contents) <= self::MAX_TEXT_BYTES) {
            return Response::text("# {$file->name}\n\n".$file->text());
        }

        $url = URL::temporarySignedRoute('tickets.attachment', now()->addMinutes(TicketAttachmentController::MINUTES), [
            'user' => $this->user($request)->getKey(),
            'repository' => $repository->getKey(),
            'reference' => (string) $validated['reference'],
            'number' => (int) $validated['number'],
        ]);
        $name = basename(str_replace('\\', '/', $file->name)) ?: 'attachment';
        $size = number_format(strlen($file->contents) / 1024, 0, '.', '').' KB';

        $text = "# {$file->name}\n\n{$file->mimeType}, {$size}. Download it within ".TicketAttachmentController::MINUTES." minutes:\n\n```sh\ncurl -fsSL -o ".escapeshellarg($name).' '.escapeshellarg($url)."\n```";

        if ($file->isText()) {
            $lines = array_slice(preg_split('/\r?\n/', $file->text()) ?: [], 0, self::PREVIEW_LINES);
            $text .= "\n\nIts first lines:\n\n```\n".mb_substr(implode("\n", $lines), 0, 4000)."\n```";
        }

        return Response::text($text);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->repositorySchema($schema),
            'reference' => $schema->string()->description('The ticket, as the `ticket` tool gave it: PROJ-123, or #1234.')->required(),
            'number' => $schema->integer()->description('The attachment\'s number in the ticket\'s `attachments`.')->required(),
        ];
    }
}
