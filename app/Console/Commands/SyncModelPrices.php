<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ModelPrice;
use App\Models\ReviewRun;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SyncModelPrices extends Command
{
    protected $signature = 'review:sync-model-prices';

    protected $description = 'Fetch model token prices so review costs can be worked out from usage';

    /**
     * LiteLLM publishes its price list as one file: every model it knows, keyed by
     * model id, with per-token prices including the cache read and cache write
     * rates that a review's usage is mostly made of.
     */
    public const CATALOGUE = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /**
     * The kinds of model a review runs on. The list also holds embedding, image
     * and audio models; the Codex models are listed under `responses`.
     *
     * @var array<int, string>
     */
    public const MODES = ['chat', 'responses'];

    /**
     * The providers a review can run under. Codex reviews report OpenAI model ids,
     * and a run whose model has no price is recorded with no cost at all rather
     * than with a partial one - so a missing provider here reads as "free".
     *
     * @var array<int, string>
     */
    public const PROVIDERS = ['anthropic', 'openai'];

    public function handle(): int
    {
        $written = 0;
        $catalogue = $this->catalogue();
        $failed = $catalogue === null ? self::PROVIDERS : [];

        foreach ($catalogue === null ? [] : self::PROVIDERS as $provider) {
            $stored = $this->storeProvider($provider, $catalogue);

            // A provider the list no longer names is a list that changed shape,
            // not a provider without models.
            if ($stored === 0) {
                $this->error("The model catalogue has no prices for {$provider}.");
                $failed[] = $provider;
            }

            $written += $stored;
        }

        $costed = $this->costWhatCouldNotBeCosted();

        if ($failed !== []) {
            // Yesterday's prices beat none, so nothing is removed; but a partial
            // sync must not report success, or a stale provider goes unnoticed.
            $this->error('Could not refresh '.implode(', ', $failed)."; those prices are unchanged. Stored {$written} model(s) from the rest.");

            return self::FAILURE;
        }

        $this->info("Stored prices for {$written} model(s), and costed {$costed} run(s) that had none.");

        return self::SUCCESS;
    }

    /**
     * Prices the reviews that could not be priced when they reported.
     *
     * A run records its tokens exactly but usually not its money, so the cost is
     * worked out from the price list — and a model released since that list was
     * last fetched has no price, which leaves the run showing no cost at all. That
     * is the common case: a new model appears, every review on it reads as free,
     * and the next day's catalogue has it. This is the second half of that day.
     *
     * Runs after the fetch and before the exit, so a partial sync still backfills
     * whatever the providers it did reach made possible.
     */
    protected function costWhatCouldNotBeCosted(): int
    {
        $costed = 0;

        // Chunked by id: estimateCost() writes to the rows being read, and a
        // cursor over a result set it is changing is a good way to skip half of it.
        ReviewRun::query()->uncosted()->chunkById(200, function ($runs) use (&$costed): void {
            foreach ($runs as $run) {
                if ($run->estimateCost()) {
                    $costed++;
                    $this->line(sprintf('  %s %s on %s: $%s', $run->slug, substr((string) $run->commit, 0, 7), $run->model ?? 'unknown', $run->cost));
                }
            }
        });

        return $costed;
    }

    /**
     * @return array<string, mixed>|null the price list by model id, or null when it
     *                                   could not be read
     */
    protected function catalogue(): ?array
    {
        try {
            $response = Http::timeout(30)->connectTimeout(5)->get(self::CATALOGUE);
        } catch (ConnectionException $exception) {
            $this->error("The model catalogue could not be reached: {$exception->getMessage()}");

            return null;
        }

        if (! $response->successful()) {
            $this->error("The model catalogue answered HTTP {$response->status()}.");

            return null;
        }

        $entries = $response->json();

        if (! is_array($entries) || $entries === []) {
            $this->error('The model catalogue returned nothing.');

            return null;
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $catalogue
     * @return int the number of models stored
     */
    protected function storeProvider(string $provider, array $catalogue): int
    {
        $written = 0;

        foreach ($catalogue as $model => $entry) {
            if (! is_array($entry) || ($entry['litellm_provider'] ?? null) !== $provider) {
                continue;
            }

            if (! in_array($entry['mode'] ?? null, self::MODES, true)) {
                continue;
            }

            // A model with no input price is not something a review can be
            // costed against; storing it would only make estimates look possible.
            if (! isset($entry['input_cost_per_token'])) {
                continue;
            }

            ModelPrice::updateOrCreate(['model' => (string) $model], [
                'input' => $entry['input_cost_per_token'],
                'output' => $entry['output_cost_per_token'] ?? null,
                'cache_read' => $entry['cache_read_input_token_cost'] ?? null,
                'cache_creation' => $entry['cache_creation_input_token_cost'] ?? null,
                'source' => 'litellm',
                'fetched_at' => now(),
            ]);

            $written++;
        }

        return $written;
    }
}
