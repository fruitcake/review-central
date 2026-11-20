<?php

declare(strict_types=1);

namespace App\Runners;

use App\Exceptions\UnknownDriver;
use App\Services\PlatformSettings;

/**
 * The runners from config/review.php, by name: how a review runs.
 */
class Runners
{
    public const DEFAULT = ContainerRunner::NAME;

    /**
     * Without a choice, the runners in the order they are taken: a Cloudflare
     * sandbox first, where the pull request's code runs away from this server and
     * the AI provider's key never enters it; then the diff, which runs nothing.
     * Docker only when someone picks it: its container holds the key, next to
     * this server.
     */
    public const PREFERRED = [CloudflareRunner::NAME, DiffRunner::NAME];

    /**
     * The platform's runner: the one an administrator picked under Settings, kept
     * also when it is not set up, so the repositories say so in their readiness
     * rather than run on a runner nobody chose. Without a pick, the configured one
     * when it is set up, else the first of PREFERRED that is, else the first that
     * is at all: a fresh installation reviews from the diff, which needs only a key.
     */
    public function default(): string
    {
        $picked = app(PlatformSettings::class)->runner();

        if ($picked !== null && is_string(config("review.runners.{$picked}"))) {
            return $picked;
        }

        $configured = config('review.default_runner');
        $known = is_string($configured) && is_string(config("review.runners.{$configured}"));

        if ($known && $this->get($configured)->available()) {
            return $configured;
        }

        $options = $this->options();

        foreach (self::PREFERRED as $name) {
            if (array_key_exists($name, $options)) {
                return $name;
            }
        }

        return array_key_first($options) ?? ($known ? $configured : self::DEFAULT);
    }

    /**
     * The runner a repository runs on: its own choice, or the platform's.
     */
    public function nameFor(?string $chosen): string
    {
        return $chosen !== null && is_string(config("review.runners.{$chosen}")) ? $chosen : $this->default();
    }

    /**
     * The runner that runs a reviewer: the one named when it is one that does,
     * else the platform's when that does, else the first that is set up. What a
     * trial or a security review runs on when the repository reviews from the diff.
     */
    public function containerFor(?string $chosen = null): ContainerRunner
    {
        foreach ([$chosen, $this->default()] as $name) {
            if ($name !== null && is_string(config("review.runners.{$name}")) && ($runner = $this->get($name)) instanceof ContainerRunner) {
                return $runner;
            }
        }

        foreach (array_keys($this->options()) as $name) {
            if (($runner = $this->get($name)) instanceof ContainerRunner) {
                return $runner;
            }
        }

        return app(ContainerRunner::class);
    }

    /**
     * @throws UnknownDriver
     */
    public function get(string $name): ReviewRunner
    {
        $class = config("review.runners.{$name}");

        if (! is_string($class)) {
            throw new UnknownDriver("No review runner is registered as \"{$name}\".");
        }

        return app($class);
    }

    /**
     * The runners this installation can run, for a repository or a trial to choose from.
     *
     * @return array<string, string> name to label
     */
    public function options(): array
    {
        $options = [];

        foreach (config('review.runners', []) as $name => $class) {
            if (is_string($name) && $this->get($name)->available()) {
                $options[$name] = $this->get($name)->label();
            }
        }

        return $options;
    }
}
