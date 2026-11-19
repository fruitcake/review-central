<?php

declare(strict_types=1);

namespace App\SourceControl;

/**
 * A webhook delivery in the terms the review cares about.
 */
final readonly class WebhookEvent
{
    public const COMMENT_CREATED = 'comment_created';

    public const PULL_REQUEST_CHANGED = 'pull_request_changed';

    /**
     * @param  string  $type  one of the constants, or the host's own name for anything else
     * @param  array<string, mixed>  $pullRequest  in the shape SourceControl documents
     * @param  string|null  $comment  the text of the comment a delivery is about
     * @param  int|null  $commentId  that comment on its host, to reply under
     * @param  string|null  $commentAuthor  who wrote it, as their host shows them
     * @param  int|null  $commentParentId  the comment it is a reply to
     * @param  string|null  $commentAuthorId  who wrote it, as their host mentions them
     * @param  string|null  $commentAuthorNickname  who wrote it, by the name they sign in with
     */
    public function __construct(
        public string $type,
        public string $workspace,
        public string $slug,
        public array $pullRequest = [],
        public ?string $comment = null,
        public ?int $commentId = null,
        public ?string $commentAuthor = null,
        public ?int $commentParentId = null,
        public ?string $commentAuthorId = null,
        public ?string $commentAuthorNickname = null,
    ) {}
}
