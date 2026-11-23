<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\ActionRefused;
use App\Exceptions\RepositoryNotFound;
use App\Services\TicketLookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Reads a file attached to a ticket: an image (a screenshot, a design) is returned as an image to look at, a text file (a log, CSV, JSON) as text.

    The `ticket` tool lists a ticket's attachments under `attachments`, each with its `number`; pass the ticket's `reference` and that `number`. Read the ones that matter for the work, such as a screenshot of the bug or the design to build; there is no need to read them all. One that is not `readable` is kept elsewhere, such as a Google Drive link, and is not read here.
    TXT)]
class TicketAttachmentTool extends ReviewTool
{
    /** Images are scaled down to this longest side: larger costs tokens without showing more. */
    public const MAX_IMAGE_SIDE = 1568;

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
            $file = $tickets->attachment($this->repository($request), (string) $validated['reference'], (int) $validated['number']);
        } catch (RepositoryNotFound|ActionRefused $exception) {
            return Response::error($exception->getMessage());
        }

        if ($file->isImage()) {
            $image = $file->fitWithin(self::MAX_IMAGE_SIDE);

            return Response::make([Response::text($image->name), Response::image($image->contents, $image->mimeType)]);
        }

        if ($file->isText()) {
            return Response::text("# {$file->name}\n\n".$file->contents);
        }

        return Response::error("{$file->name} is a {$file->mimeType} file; only images and text files are read.");
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
