<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Connection;
use App\Models\Tenant;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

/**
 * Takes secrets out of text, also when they are written in another form: with
 * spaces or dashes between their characters, reversed, in base64 or in hex.
 *
 * A reviewer reads text a pull request's author, or anyone who comments on it,
 * wrote, and can be talked into writing a key it can reach. This is the last
 * check on what then leaves: the log, and everything posted on the host. It is
 * no boundary: a secret cut in pieces, or encoded otherwise, still passes.
 */
class SecretFilter
{
    public const REPLACEMENT = '[secret]';

    /** A value this short is not a secret worth searching for, and would match by accident. */
    public const SHORTEST = 8;

    /** At most this many characters between two of a secret's own, as in "s k - a n t". */
    protected const SEPARATORS = '[\s\-_.,:;\'"`+|\/\\\\*]{0,3}';

    /** @var list<string> */
    protected array $patterns = [];

    /**
     * @param  iterable<mixed>  $secrets
     */
    public function __construct(iterable $secrets)
    {
        $values = [];

        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen(trim($secret)) >= self::SHORTEST) {
                $values[trim($secret)] = true;
            }
        }

        // The longest first, so a secret that holds another is taken out whole.
        $values = array_keys($values);
        usort($values, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($values as $secret) {
            $this->patterns[] = $this->spaced($secret);
            $this->patterns[] = $this->spaced(strrev($secret));

            foreach ($this->encodings($secret) as $encoded) {
                $this->patterns[] = '/'.preg_quote($encoded, '/').'/i';
            }
        }
    }

    /**
     * The credentials of every service a tenant connected: its AI providers'
     * keys, which its reviewers run with, and its hosts' and trackers' tokens.
     */
    public static function forTenant(Tenant|int $tenant): self
    {
        $secrets = [];

        foreach (Connection::withTrashed()->where('tenant_id', $tenant instanceof Tenant ? $tenant->getKey() : $tenant)->get() as $connection) {
            array_push($secrets, $connection->webhook_secret, ...array_values($connection->credentials ?? []));
        }

        return new self($secrets);
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    public function filter(string $text): string
    {
        if ($this->patterns === [] || $text === '') {
            return $text;
        }

        $filtered = preg_replace($this->patterns, self::REPLACEMENT, $text);

        // A pattern that fails says nothing about the text: nothing of it goes out
        // rather than all of it.
        return $filtered ?? self::REPLACEMENT;
    }

    /**
     * Every string in a structure, its keys left alone.
     */
    public function filterValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $copy = clone $value;

            foreach (get_object_vars($copy) as $key => $item) {
                $copy->{$key} = $this->filterValue($item);
            }

            return $copy;
        }

        return match (true) {
            is_string($value) => $this->filter($value),
            is_array($value) => array_map($this->filterValue(...), $value),
            default => $value,
        };
    }

    /**
     * A request's body without the secrets: a JSON body string by string, so an
     * encoder's escaping (a "/" as "\/") does not hide one, and any other whole.
     */
    public function request(RequestInterface $request): RequestInterface
    {
        if ($this->patterns === []) {
            return $request;
        }

        $body = (string) $request->getBody();

        if ($body === '') {
            return $request;
        }

        // Objects stay objects, so an empty one is not sent back as a list.
        $decoded = str_contains($request->getHeaderLine('Content-Type'), 'json') ? json_decode($body) : null;

        if (is_array($decoded) || is_object($decoded)) {
            $filtered = (string) json_encode($this->filterValue($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $changed = $filtered !== (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } else {
            $filtered = $this->filter($body);
            $changed = $filtered !== $body;
        }

        if (! $changed) {
            $request->getBody()->rewind();

            return $request;
        }

        return $request->withBody(Utils::streamFor($filtered))->withHeader('Content-Length', (string) strlen($filtered));
    }

    /**
     * The secret with up to a few separators between its characters.
     */
    protected function spaced(string $secret): string
    {
        return '/'.implode(self::SEPARATORS, array_map(fn (string $character): string => preg_quote($character, '/'), str_split($secret))).'/i';
    }

    /**
     * The secret in base64, standard and for URLs, at each of the three places it
     * can start in a longer encoded text, and in hex. Of base64 only the characters
     * that the secret alone decides: those at its edges depend on what is around it.
     *
     * @return list<string>
     */
    protected function encodings(string $secret): array
    {
        $length = strlen($secret);
        $encodings = [bin2hex($secret)];

        foreach ([0, 1, 2] as $offset) {
            $encoded = rtrim(base64_encode(str_repeat("\0", $offset).$secret), '=');
            $first = intdiv(8 * $offset + 5, 6);
            $last = intdiv(8 * ($offset + $length), 6);
            $part = substr($encoded, $first, $last - $first);

            array_push($encodings, $part, strtr($part, '+/', '-_'));
        }

        return array_values(array_unique($encodings));
    }
}
