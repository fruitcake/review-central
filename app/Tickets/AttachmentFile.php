<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Exceptions\AttachmentRefused;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * The contents of a ticket's attachment, read for an agent that works on the
 * ticket: an image to look at, or text to read.
 */
final readonly class AttachmentFile
{
    /** The largest file that is read at all. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Images a model can look at. */
    protected const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Text that is not text/*. */
    protected const TEXT_TYPES = ['application/json', 'application/xml', 'application/x-yaml', 'application/yaml', 'image/svg+xml'];

    public function __construct(
        public string $name,
        public string $mimeType,
        public string $contents,
    ) {}

    /**
     * The type the contents themselves show, where the tracker named none or a
     * vague one.
     */
    public static function make(string $name, ?string $mimeType, string $contents): self
    {
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: null;
        $type = $mimeType === null || $mimeType === '' || $mimeType === 'application/octet-stream' ? ($sniffed ?? 'application/octet-stream') : strtolower(trim(explode(';', $mimeType)[0]));

        return new self($name, $type, $contents);
    }

    /**
     * What a download answered, once it is clear it is the file and not too large.
     *
     * @throws AttachmentRefused
     * @throws RequestException
     */
    public static function fromResponse(TicketAttachment $attachment, Response $response): self
    {
        $contents = $response->throw()->body();

        if (strlen($contents) > self::MAX_BYTES) {
            throw new AttachmentRefused("{$attachment->name} is larger than ".(self::MAX_BYTES / 1024 / 1024).' MB, which is not read.');
        }

        return self::make($attachment->name, $attachment->mimeType ?? $response->header('Content-Type'), $contents);
    }

    /**
     * @throws AttachmentRefused when the tracker says it is too large, or it lives elsewhere
     */
    public static function check(TicketAttachment $attachment): string
    {
        if ($attachment->downloadUrl === null) {
            throw new AttachmentRefused("{$attachment->name} is not kept in the tracker itself, so it is not read here".($attachment->viewUrl === null ? '.' : ": open {$attachment->viewUrl}."));
        }

        if ($attachment->size !== null && $attachment->size > self::MAX_BYTES) {
            throw new AttachmentRefused("{$attachment->name} is larger than ".(self::MAX_BYTES / 1024 / 1024).' MB, which is not read.');
        }

        return $attachment->downloadUrl;
    }

    public function isImage(): bool
    {
        return in_array($this->mimeType, self::IMAGE_TYPES, true);
    }

    public function isText(): bool
    {
        return str_starts_with($this->mimeType, 'text/') || in_array($this->mimeType, self::TEXT_TYPES, true);
    }

    /**
     * The image scaled down to this longest side, or itself when it is smaller or
     * cannot be scaled here: a larger image costs tokens without showing more.
     */
    public function fitWithin(int $maxSide): self
    {
        if (! function_exists('imagecreatefromstring')) {
            return $this;
        }

        [$width, $height] = @getimagesizefromstring($this->contents) ?: [0, 0];

        if ($width === 0 || max($width, $height) <= $maxSide || ! ($image = @imagecreatefromstring($this->contents))) {
            return $this;
        }

        $scale = $maxSide / max($width, $height);
        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        if ($scaled === false) {
            return $this;
        }

        ob_start();

        // A photo stays a JPEG; a screenshot stays a sharp PNG.
        if (in_array($this->mimeType, ['image/jpeg', 'image/webp'], true)) {
            imagejpeg($scaled, null, 85);
            $type = 'image/jpeg';
        } else {
            imagepng($scaled, null, 9);
            $type = 'image/png';
        }

        return new self($this->name, $type, (string) ob_get_clean());
    }
}
