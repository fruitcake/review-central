<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Runs collect-usage-codex.sh on a rollout that names no model, as Codex sometimes
 * writes one, with the config Codex ran with and the model set for the review.
 *
 * @return array<string, mixed>
 */
function codexUsage(?string $configModel, ?string $reviewModel): array
{
    $dir = sys_get_temp_dir().'/codex-usage-'.uniqid();
    File::ensureDirectoryExists("{$dir}/home/sessions");
    File::put("{$dir}/home/sessions/rollout.jsonl", json_encode(['type' => 'event_msg', 'payload' => ['type' => 'token_count', 'info' => ['total_token_usage' => ['input_tokens' => 1000, 'cached_input_tokens' => 400, 'output_tokens' => 50]]]])."\n");
    File::put("{$dir}/config.toml", $configModel === null ? "approval_policy = \"never\"\n" : "model = \"{$configModel}\"\n");

    $result = Process::path($dir)
        ->env(array_filter([
            'CODEX_HOME' => "{$dir}/home",
            'HOME' => "{$dir}/none",
            'REVIEW_AGENT_CONFIG' => "{$dir}/config.toml",
            'REVIEW_USAGE_FILE' => "{$dir}/usage.json",
            'REVIEW_USAGE_SINCE' => "{$dir}/missing",
            'CODEX_REVIEW_MODEL' => $reviewModel,
        ]))
        ->run(['sh', base_path('resources/review/skill/collect-usage-codex.sh')]);

    expect($result->successful())->toBeTrue();
    $usage = File::json("{$dir}/usage.json");
    File::deleteDirectory($dir);

    return $usage;
}

it('files a rollout without a model under the model the config Codex ran with names, as a trial sets it', function () {
    expect(array_keys(codexUsage('gpt-trial', 'gpt-team')['usage']))->toBe(['gpt-trial']);
});

it('files it under the model set for the review when the config names none', function () {
    $usage = codexUsage(null, 'gpt-team');

    expect($usage['usage'])->toBe(['gpt-team' => ['input' => 600, 'output' => 50, 'cache_read' => 400, 'cache_creation' => 0]])
        ->and($usage['model_source'])->toBe('configured');
});
