<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModelPrice;
use App\Models\Repository;
use App\Models\ReviewRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelPriceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function price(string $model, array $overrides = []): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'model' => $model,
            'input' => '0.000003',
            'output' => '0.000015',
            'cache_read' => '0.0000003',
            'cache_creation' => '0.00000375',
            'fetched_at' => now(),
        ], $overrides));
    }

    /**
     * The price list as LiteLLM publishes it: one object, keyed by model id. Both
     * providers are in it unless a test leaves one out.
     *
     * @param  array<string, array<string, mixed>>  $models
     */
    private function fakeCatalogue(array $models = []): void
    {
        $price = ['mode' => 'chat', 'input_cost_per_token' => 0.000003, 'output_cost_per_token' => 0.000015];

        Http::fake(['raw.githubusercontent.com/BerriAI/litellm/*' => Http::response($models + [
            'claude-sonnet-4-6' => $price + ['litellm_provider' => 'anthropic'],
            'gpt-6-sol' => $price + ['litellm_provider' => 'openai'],
        ])]);
    }

    public function test_it_stores_the_models_a_review_can_run_on(): void
    {
        $this->fakeCatalogue([
            'claude-sonnet-4-6' => [
                'litellm_provider' => 'anthropic',
                'mode' => 'chat',
                'input_cost_per_token' => 0.000003,
                'output_cost_per_token' => 0.000015,
                'cache_read_input_token_cost' => 0.0000003,
                'cache_creation_input_token_cost' => 0.00000375,
            ],
            // Codex models are listed as `responses`, not `chat`.
            'gpt-6-codex' => ['litellm_provider' => 'openai', 'mode' => 'responses', 'input_cost_per_token' => 0.000002],
            // Not a provider a review runs under.
            'gemini-9' => ['litellm_provider' => 'gemini', 'mode' => 'chat', 'input_cost_per_token' => 0.000001],
            // Not a model a review runs on.
            'text-embedding-9' => ['litellm_provider' => 'openai', 'mode' => 'embedding', 'input_cost_per_token' => 0.0000001],
            // No input price: nothing can be costed against it, and storing it
            // would only make an estimate look possible.
            'claude-unpriced' => ['litellm_provider' => 'anthropic', 'mode' => 'chat', 'output_cost_per_token' => 0.1],
            // The file opens with a description of its own fields.
            'sample_spec' => ['litellm_provider' => 'one of https://docs.litellm.ai/docs/providers', 'mode' => 'chat', 'input_cost_per_token' => 0.0],
        ]);

        $this->artisan('review:sync-model-prices')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['claude-sonnet-4-6', 'gpt-6-codex', 'gpt-6-sol'],
            ModelPrice::pluck('model')->all(),
        );

        $sonnet = ModelPrice::where('model', 'claude-sonnet-4-6')->firstOrFail();
        $this->assertEqualsWithDelta(0.000003, (float) $sonnet->input, 1e-12);
        $this->assertEqualsWithDelta(0.000015, (float) $sonnet->output, 1e-12);
        $this->assertEqualsWithDelta(0.0000003, (float) $sonnet->cache_read, 1e-12);
        $this->assertEqualsWithDelta(0.00000375, (float) $sonnet->cache_creation, 1e-12);
    }

    public function test_a_catalogue_without_one_of_the_providers_fails_the_sync(): void
    {
        // A Codex review reports OpenAI model ids. Without them a run is recorded
        // with no cost at all, which reads on the chart as though it were free.
        Http::fake(['raw.githubusercontent.com/BerriAI/litellm/*' => Http::response([
            'claude-sonnet-4-6' => ['litellm_provider' => 'anthropic', 'mode' => 'chat', 'input_cost_per_token' => 0.000003],
        ])]);

        $this->artisan('review:sync-model-prices')
            ->expectsOutputToContain('The model catalogue has no prices for openai')
            ->assertFailed();

        // What the list did hold is still stored.
        $this->assertSame(['claude-sonnet-4-6'], ModelPrice::pluck('model')->all());
    }

    public function test_it_prices_the_runs_that_could_not_be_priced_before(): void
    {
        $repository = $this->reviewing(Repository::factory()->create(['workspace' => 'example-co', 'slug' => 'storefront', 'name' => 'Storefront']));

        // What a run on a model released since the last catalogue fetch looks like:
        // exact tokens, no price to put against them, so no cost at all.
        $run = new ReviewRun;
        $run->tenant_id = $repository->tenant_id;
        $run->repository_id = $repository->getKey();
        $run->workspace = 'example-co';
        $run->slug = 'storefront';
        $run->branch = 'feature/review';
        $run->commit = str_repeat('a', 40);
        $run->status = ReviewRun::STATUS_FINISHED;
        $run->started_at = now();
        $run->recordUsage(['gpt-6-sol' => ['input' => 1_000_000, 'output' => 0]]);
        $run->save();

        $this->assertNull($run->cost);

        $this->fakeCatalogue();

        $this->artisan('review:sync-model-prices')
            ->expectsOutputToContain('costed 1 run(s)')
            ->assertSuccessful();

        // 1,000,000 x 3e-6. Marked estimated: derived from tokens, not reported.
        $run->refresh();
        $this->assertEqualsWithDelta(3.0, (float) $run->cost, 0.0001);
        $this->assertTrue($run->cost_estimated);
    }

    public function test_a_run_that_reported_its_own_cost_is_left_alone(): void
    {
        $this->price('claude-sonnet-4-6');

        $repository = $this->reviewing(Repository::factory()->create(['workspace' => 'example-co', 'slug' => 'storefront', 'name' => 'Storefront']));

        $run = new ReviewRun;
        $run->tenant_id = $repository->tenant_id;
        $run->repository_id = $repository->getKey();
        $run->workspace = 'example-co';
        $run->slug = 'storefront';
        $run->branch = 'feature/review';
        $run->commit = str_repeat('b', 40);
        $run->status = ReviewRun::STATUS_FINISHED;
        $run->started_at = now();
        $run->recordUsage(['claude-sonnet-4-6' => ['input' => 1_000_000]], null, 99.0);
        $run->save();

        $this->fakeCatalogue();

        $this->artisan('review:sync-model-prices')->assertSuccessful();

        // A measured cost is the better number; recomputing it from a price list
        // would quietly replace what the run actually reported.
        $this->assertEqualsWithDelta(99.0, (float) $run->refresh()->cost, 0.0001);
    }

    public function test_a_failed_fetch_leaves_the_prices_alone(): void
    {
        $this->price('claude-sonnet-4-6');

        Http::fake(['raw.githubusercontent.com/BerriAI/litellm/*' => Http::response([], 503)]);

        $this->artisan('review:sync-model-prices')->assertFailed();

        // Yesterday's prices beat none; the command says so and changes nothing.
        $this->assertSame(1, ModelPrice::count());
    }

    public function test_an_unreachable_catalogue_fails_the_sync_instead_of_throwing(): void
    {
        $this->price('claude-sonnet-4-6');

        Http::fake(['raw.githubusercontent.com/BerriAI/litellm/*' => Http::failedConnection()]);

        $this->artisan('review:sync-model-prices')
            ->expectsOutputToContain('The model catalogue could not be reached')
            ->assertFailed();

        $this->assertSame(1, ModelPrice::count());
    }

    public function test_it_costs_a_run_from_its_tokens(): void
    {
        $this->price('claude-sonnet-4-6');

        $cost = ModelPrice::estimate([
            'claude-sonnet-4-6' => ['input' => 40, 'output' => 8018, 'cache_read' => 1094511, 'cache_creation' => 112237],
        ]);

        // 40×3e-6 + 8018×15e-6 + 1094511×3e-7 + 112237×3.75e-6
        $this->assertEqualsWithDelta(0.8696, $cost, 0.0001);
    }

    public function test_a_dated_model_id_matches_the_undated_price(): void
    {
        $this->price('claude-sonnet-4-6');

        // Claude Code reports one form, a catalogue may carry the other.
        $this->assertNotNull(ModelPrice::estimate(['claude-sonnet-4-6-20260115' => ['input' => 1000]]));
    }

    public function test_an_unknown_model_costs_nothing_rather_than_too_little(): void
    {
        $this->price('claude-sonnet-4-6');

        // Leaving a model out would report the run as cheaper than it was, which
        // is worse than reporting no cost at all.
        $this->assertNull(ModelPrice::estimate([
            'claude-sonnet-4-6' => ['input' => 1000],
            'some-new-model' => ['input' => 1000],
        ]));
    }

    public function test_stale_prices_are_not_used(): void
    {
        $this->price('claude-sonnet-4-6', ['fetched_at' => now()->subDays(ModelPrice::STALE_AFTER_DAYS + 1)]);

        // Prices change. A confident number from an old list is worse than none.
        $this->assertNull(ModelPrice::estimate(['claude-sonnet-4-6' => ['input' => 1000]]));
    }

    public function test_a_missing_rate_for_tokens_that_were_used_is_not_treated_as_free(): void
    {
        $this->price('claude-sonnet-4-6', ['cache_creation' => null]);

        $this->assertNull(ModelPrice::estimate([
            'claude-sonnet-4-6' => ['input' => 100, 'cache_creation' => 50_000],
        ]));

        // But a rate that is missing for tokens the run never used is irrelevant.
        $this->assertNotNull(ModelPrice::estimate([
            'claude-sonnet-4-6' => ['input' => 100, 'cache_creation' => 0],
        ]));
    }
}
