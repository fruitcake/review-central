<?php

declare(strict_types=1);

namespace App\Runners\Container;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessFailedException;

/**
 * How a step ended that no process on this worker ran: its exit code, and what it
 * printed where that was kept.
 */
final readonly class SandboxResult implements ProcessResult
{
    public function __construct(protected string $command, protected int $exitCode, protected string $output = '') {}

    public function command(): string
    {
        return $this->command;
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function seeInOutput(string $output): bool
    {
        return str_contains($this->output, $output);
    }

    public function errorOutput(): string
    {
        return '';
    }

    public function seeInErrorOutput(string $output): bool
    {
        return false;
    }

    public function throw(?callable $callback = null): static
    {
        if ($this->successful()) {
            return $this;
        }

        $exception = new ProcessFailedException($this);

        if ($callback !== null) {
            $callback($this, $exception);
        }

        throw $exception;
    }

    public function throwIf(bool $condition, ?callable $callback = null): static
    {
        return $condition ? $this->throw($callback) : $this;
    }
}
