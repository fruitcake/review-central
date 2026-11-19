<?php

declare(strict_types=1);

namespace App\Runners\Container;

use Closure;

/**
 * Appends a step's output to a run's log, a whole line at a time.
 *
 * By line, because output arrives in pieces cut anywhere: a secret split over two
 * pieces would pass a search for it, and a page would show half a line.
 */
class RunLogWriter
{
    /** A value this short is not a secret worth searching for, and would match by accident. */
    protected const SHORTEST_SECRET = 8;

    protected const TELL_EVERY_SECONDS = 1.0;

    protected const COPY_EVERY_SECONDS = 5.0;

    protected string $pending = '';

    /** When the open pages were last told the log grew. */
    protected float $told = 0.0;

    /** When the log was last copied to the disk another server reads it from. */
    protected float $copied = 0.0;

    /** Whether lines were written since. */
    protected bool $untold = false;

    /** @var array<int, string> */
    protected array $secrets;

    /**
     * @param  array<string, string>  $environment
     * @param  Closure(): mixed|null  $grew  tells the open pages the log grew
     * @param  Closure(): mixed|null  $copy  copies the log to where another server reads it
     */
    public function __construct(protected string $path, array $environment = [], protected ?Closure $grew = null, protected ?Closure $copy = null)
    {
        // The steps are told not to print these, and a tool they call may anyway.
        $this->secrets = array_values(array_filter(
            $environment,
            fn (string $value, string $name): bool => strlen($value) >= self::SHORTEST_SECRET
                && preg_match('/KEY|TOKEN|SECRET|PASSWORD|CREDENTIAL/i', $name) === 1,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * A line of the runner's own, between the steps.
     */
    public function note(string $line): void
    {
        $this->finishLine();
        $this->append([$line]);
        // A note marks a step's start or end, and is often the last line of a run:
        // the copy on the disk gets it now, not with the next line there may never be.
        $this->copied = 0.0;
        $this->tell();
    }

    /**
     * A piece of a step's output, as the process hands it over.
     */
    public function write(string $type, string $buffer): void
    {
        $lines = explode("\n", $this->pending.$buffer);
        $this->pending = (string) array_pop($lines);

        if ($lines !== []) {
            $this->append($lines);
        }
    }

    /**
     * Writes a last line that did not end in a newline.
     */
    public function flush(): void
    {
        $this->finishLine();

        // The step is done: whatever was held back for the second goes out now,
        // and the copy is brought up to date whenever it last was.
        $this->copied = 0.0;
        $this->tell();
    }

    /**
     * Writes what was held back for the rest of its line.
     */
    protected function finishLine(): void
    {
        if ($this->pending !== '') {
            $this->append([$this->pending]);
            $this->pending = '';
        }
    }

    /**
     * Tells the open pages that the log grew, when it did since they were last told.
     */
    protected function tell(): void
    {
        if ($this->untold && $this->grew !== null) {
            $this->untold = false;
            $this->told = microtime(true);
            ($this->grew)();
        }

        // The copy is the whole log each time, so not on every line.
        if ($this->copy !== null && microtime(true) - $this->copied >= self::COPY_EVERY_SECONDS) {
            $this->copied = microtime(true);
            ($this->copy)();
        }
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function append(array $lines): void
    {
        $time = now()->format('H:i:s');
        $text = '';

        foreach ($lines as $line) {
            // Colours and cursor movements mean nothing on a page, and a carriage
            // return is a progress line overwriting itself.
            $line = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e\][^\a]*\a/', '', $line);
            $line = rtrim((string) str_replace($this->secrets, '[secret]', $line), "\r");
            $line = (string) substr($line, (int) strrpos("\r".$line, "\r"));

            $text .= "{$time}  {$line}\n";
        }

        // Never in the way of the review: a log that cannot be written is only missed.
        @file_put_contents($this->path, $text, FILE_APPEND | LOCK_EX);

        // At most once a second: a reviewer prints in bursts, and a page reads the
        // whole end of the log each time it is told.
        // Lines that come within that second are told of with the next ones, or when
        // the step ends. A reviewer that then says nothing for a while leaves them
        // for the page's own poll, which is why that stays short.
        $this->untold = true;

        if (microtime(true) - $this->told >= self::TELL_EVERY_SECONDS) {
            $this->tell();
        }
    }
}
