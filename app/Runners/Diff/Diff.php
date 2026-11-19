<?php

declare(strict_types=1);

namespace App\Runners\Diff;

/**
 * A pull request's diff as a reviewer is given it: without the files nobody
 * reviews line by line, and no larger than a request can carry.
 */
final readonly class Diff
{
    /** Files whose content is made by a tool, which a review reads nothing in. */
    protected const GENERATED = '~(^|/)(composer\.lock|package-lock\.json|yarn\.lock|pnpm-lock\.yaml|bun\.lockb?|Cargo\.lock|Gemfile\.lock|poetry\.lock)$|\.min\.(js|css)$|\.map$|(^|/)(dist|build|vendor|node_modules)/~';

    /**
     * @param  string  $text  what is left to review
     * @param  array<int, string>  $leftOut  the files that were in the diff and are not in the text, each with why
     */
    public function __construct(
        public string $text,
        public array $leftOut,
    ) {}

    /**
     * @param  int  $limit  the most bytes of diff a request may carry
     */
    public static function from(string $diff, int $limit): self
    {
        $kept = '';
        $leftOut = [];

        // Each file's part starts at its own "diff --git" line.
        foreach (preg_split('/^(?=diff --git )/m', $diff) ?: [] as $file) {
            if (trim($file) === '') {
                continue;
            }

            $path = preg_match('~^diff --git a/.+? b/(.+)$~m', $file, $matches) === 1 ? trim($matches[1]) : '(unnamed)';

            if (preg_match(self::GENERATED, $path) === 1) {
                $leftOut[] = "{$path} (generated)";
            } elseif (str_contains($file, "\nBinary files ") || str_contains($file, "\nGIT binary patch")) {
                $leftOut[] = "{$path} (binary)";
            } elseif (strlen($kept) + strlen($file) > $limit) {
                $leftOut[] = "{$path} (the diff is too large to send whole)";
            } else {
                $kept .= $file;
            }
        }

        return new self($kept, $leftOut);
    }

    /**
     * Whether files a person would want reviewed are missing, as opposed to ones
     * nobody reads.
     */
    public function cutShort(): bool
    {
        return array_filter($this->leftOut, fn (string $file): bool => str_ends_with($file, 'whole)')) !== [];
    }
}
