<?php

declare(strict_types=1);

namespace App\SourceControl;

/**
 * A pull request as its address in the browser names it.
 */
final readonly class PullRequestLink
{
    public const BITBUCKET = 'bitbucket';

    public const GITHUB = 'github';

    /**
     * @param  string  $host  the driver of the connection that reads it
     */
    public function __construct(
        public string $workspace,
        public string $slug,
        public int $id,
        public string $host = self::BITBUCKET,
    ) {}

    /**
     * Reads the address of a pull request on Bitbucket Cloud or on GitHub, with
     * or without what follows the number.
     */
    public static function parse(string $url): ?self
    {
        if (preg_match('~^https?://bitbucket\.org/([\w.-]+)/([\w.-]+)/pull-requests/(\d+)(?:[/?#].*)?$~', trim($url), $matches) === 1) {
            return new self($matches[1], $matches[2], (int) $matches[3]);
        }

        if (preg_match('~^https?://github\.com/([\w.-]+)/([\w.-]+)/pull/(\d+)(?:[/?#].*)?$~', trim($url), $matches) === 1) {
            return new self($matches[1], $matches[2], (int) $matches[3], self::GITHUB);
        }

        return null;
    }
}
