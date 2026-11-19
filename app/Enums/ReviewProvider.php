<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which reviewer the AI review runs: REVIEW_PROVIDER in the scripts.
 */
enum ReviewProvider: string
{
    /** A current Claude Code CLI, run headless in the step. */
    case ClaudeCode = 'claude-code';

    /** An older name for Claude Code, kept so runs that went by it still show a name. */
    case ClaudeAcp = 'claude-acp';

    /** A current Codex CLI, run headless in the step. */
    case CodexCli = 'codex-cli';

    /** An older name for the Codex CLI, kept so runs that went by it still show a name. */
    case CodexAcp = 'codex-acp';

    /** OpenCodeReview with its own LLM call. */
    case Ocr = 'ocr';

    /** The Pi coding agent, run headless in the step: for trials only, for now. */
    case Pi = 'pi';

    /**
     * Reviewers only a trial can ask for: no repository's own review runs them yet.
     * They call the API of either vendor, so any AI provider key will do.
     *
     * @return array<int, self>
     */
    public static function onTrial(): array
    {
        return [self::Pi];
    }

    public function text(): string
    {
        return match ($this) {
            self::ClaudeCode => 'Claude Code',
            self::ClaudeAcp => 'Claude ACP',
            self::CodexCli => 'Codex CLI',
            self::CodexAcp => 'Codex ACP',
            self::Ocr => 'OpenCodeReview',
            self::Pi => 'Pi',
        };
    }
}
