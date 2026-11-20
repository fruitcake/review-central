<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How to get a key, token or other credential a form asks for: where it is made,
 * the steps there, what it has to be allowed to do and why. Shown next to the
 * field, so nobody has to leave the form to find out what to paste into it.
 *
 * The steps, permissions and notes may use inline Markdown: `code`, **bold** and
 * links. Anything else is shown as text.
 */
final readonly class SetupGuide
{
    /**
     * @param  string  $title  what is being made, as the modal's heading
     * @param  string  $url  the page where it is made
     * @param  string  $linkLabel  what that page is, as the link's text
     * @param  list<string>  $steps  in order, from that page on
     * @param  array<string, string>  $permissions  each scope or permission it needs, to why it needs it
     * @param  list<string>  $notes  what is worth knowing besides
     */
    public function __construct(
        public string $title,
        public string $url,
        public string $linkLabel,
        public array $steps,
        public array $permissions = [],
        public array $notes = [],
    ) {}
}
