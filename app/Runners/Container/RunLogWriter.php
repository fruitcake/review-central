<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Support\SecretFilter;
use Closure;

/**
 * Appends a step's output to a run's log, a whole line at a time.
 *
 * By line, because output arrives in pieces cut anywhere: a secret split over two
 * pieces would pass a search for it, and a page would show half a line.
 */
class RunLogWriter
{
    /**
     * The steps of a run, as the runner notes them ("── review ──") and the
     * scripts mark them ("##[phase] composer"), in the order they come.
     */
    public const PHASES = [
        'clone', 'fetch', 'precheck', 'upload', 'start', 'unpack', 'vendor', 'review', 'prepare', 'scan',
        'composer', 'tools', 'cli', 'reviewer', 'usage', 'keep', 'download', 'report',
    ];

    protected const TELL_EVERY_SECONDS = 1.0;

    protected const COPY_EVERY_SECONDS = 5.0;

    /** A host's check is a request or two each time, and its readers need no more. */
    protected const PROGRESS_EVERY_SECONDS = 20.0;

    protected string $pending = '';

    /** When the open pages were last told the log grew. */
    protected float $told = 0.0;

    /** When the log was last copied to the disk another server reads it from. */
    protected float $copied = 0.0;

    /** When the host was last shown how far the review is. */
    protected float $shown = 0.0;

    /** Whether lines were written since. */
    protected bool $untold = false;

    protected SecretFilter $secrets;

    /**
     * @param  array<string, string>  $environment
     * @param  Closure(): mixed|null  $grew  tells the open pages the log grew
     * @param  Closure(): mixed|null  $copy  copies the log to where another server reads it
     * @param  Closure(): mixed|null  $progress  shows the end of the log on the pull request, where the host can
     */
    public function __construct(protected string $path, array $environment = [], protected ?Closure $grew = null, protected ?Closure $copy = null, protected ?Closure $progress = null)
    {
        // The first lines are worth a wait: a check that says only "Reviewing" says little.
        $this->shown = $this->clock() - self::PROGRESS_EVERY_SECONDS / 2;

        // The steps are told not to print these, and a tool they call may anyway.
        // Also written otherwise: spaced out, reversed, in base64 or hex.
        $this->secrets = new SecretFilter(array_filter(
            $environment,
            fn (string $name): bool => preg_match('/KEY|TOKEN|SECRET|PASS|CREDENTIAL|PRIVATE|AUTH/i', $name) === 1,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * A line of the runner's own, between the steps.
     */
    public function note(string $line): void
    {
        if (preg_match('/^── ([a-z]+) ──$/u', $line, $match) === 1) {
            $this->phase($match[1]);
        }

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
            $this->told = $this->clock();
            ($this->grew)();
        }

        // The copy is the whole log each time, so not on every line.
        if ($this->copy !== null && $this->clock() - $this->copied >= self::COPY_EVERY_SECONDS) {
            $this->copied = $this->clock();
            ($this->copy)();
        }

        // Never in the way of the review: a host that does not answer is only not shown.
        if ($this->progress !== null && $this->clock() - $this->shown >= self::PROGRESS_EVERY_SECONDS) {
            $this->shown = $this->clock();
            rescue($this->progress, null, false);
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
            // A script's mark of where a step starts, shown as the runner's own.
            if (preg_match('/^##\[phase\] ([a-z]+)\s*$/', $line, $match) === 1) {
                $this->phase($match[1]);
                $line = "── {$match[1]} ──";
            }

            // Colours and cursor movements mean nothing on a page, and a carriage
            // return is a progress line overwriting itself.
            $line = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e\][^\a]*\a/', '', $line);
            $line = rtrim($this->secrets->filter($line), "\r");
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

        if ($this->clock() - $this->told >= self::TELL_EVERY_SECONDS) {
            $this->tell();
        }
    }

    /**
     * Notes when a step started, beside the log, for the run's metrics. Only the
     * steps there are: the reviewer prints what a pull request may put in its
     * mouth, and a step it names is not one.
     */
    protected function phase(string $name): void
    {
        if (in_array($name, self::PHASES, true)) {
            @file_put_contents($this->path.'.phases', now()->getTimestamp()." {$name}\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Seconds now, to the microsecond, on the application's clock.
     */
    protected function clock(): float
    {
        return (float) now()->format('U.u');
    }
}
