<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConnectionKind;
use App\Models\CapacitySample;
use App\Models\Connection;
use App\Models\ModelPrice;
use App\Models\Repository;
use App\Models\ReviewInstruction;
use App\Models\ReviewRating;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReviewInstructions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A made-up team with a month of reviews, to look around in or take screenshots of:
 *
 *     php artisan migrate:fresh --seed --seeder=DemoSeeder
 *
 * Sign in as demo@example.com with the password "password". Nothing here talks to
 * a real host: the connections hold placeholder credentials.
 */
class DemoSeeder extends Seeder
{
    /** The same reviews every time, so screenshots can be taken again. */
    protected const SEED = 20261119;

    public function run(): void
    {
        mt_srand(self::SEED);

        if (ReviewInstruction::query()->whereNull('tenant_id')->doesntExist()) {
            ReviewInstructions::import(resource_path(ReviewInstructions::BUNDLED_DIRECTORY));
        }

        ModelPrice::query()->create([
            'model' => 'claude-sonnet-5-5', 'input' => '3', 'output' => '15', 'cache_read' => '0.3', 'cache_creation' => '3.75',
            'source' => 'demo', 'fetched_at' => now(),
        ]);

        // A day of the scheduler's measurements of this server, every ten minutes.
        foreach (range(144, 0) as $step) {
            $busy = (int) round(2 + 2 * sin($step / 12));
            CapacitySample::query()->create([
                'sampled_at' => now()->subMinutes($step * 10), 'running' => $busy, 'running_sandboxes' => 0,
                'waiting' => max(0, $busy - 3), 'held' => 0, 'workers' => 4,
                'load' => (string) round(0.4 + $busy * 0.6, 2), 'cpus' => 4,
                'memory_used_percent' => (string) (38 + $busy * 7), 'disk_used_percent' => '41',
            ]);
        }

        $user = User::query()->create([
            'name' => 'Dana Demo', 'email' => 'demo@example.com', 'password' => 'password', 'is_admin' => true,
        ]);

        $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme', 'monthly_budget' => 150]);
        $user->tenants()->attach($tenant);

        foreach (['Northwind' => 'storefront-2', 'Globex' => 'billing'] as $other => $slug) {
            $team = Tenant::query()->create(['name' => $other, 'slug' => Str::slug($other), 'monthly_budget' => 100]);
            $connection = Connection::query()->create([
                'tenant_id' => $team->id, 'kind' => ConnectionKind::SourceControl, 'driver' => 'bitbucket', 'name' => 'Bitbucket',
                'credentials' => ['access_token' => 'demo'], 'settings' => ['workspace' => Str::slug($other)],
            ]);
            Connection::factory()->openai('sk-demo')->create(['tenant_id' => $team->id]);
            $repository = Repository::query()->create([
                'tenant_id' => $team->id, 'connection_id' => $connection->id, 'workspace' => Str::slug($other), 'slug' => $slug, 'review_stack' => ['laravel'], 'review_runner' => 'diff',
            ]);

            foreach (range(1, mt_rand(6, 14)) as $number) {
                $this->review($repository, 1000 + $number + strlen($other) * 100, now()->subDays(mt_rand(0, 6))->setTime(mt_rand(8, 18), 0), ['result' => mt_rand(0, 2) ? 'PASSED' : 'FAILED', 'findings' => []]);
            }
        }

        Tenant::query()->create(['name' => 'Initech', 'slug' => 'initech', 'monthly_budget' => 50]);

        $bitbucket = Connection::query()->create([
            'tenant_id' => $tenant->id, 'kind' => ConnectionKind::SourceControl, 'driver' => 'bitbucket', 'name' => 'Bitbucket',
            'credentials' => ['access_token' => 'demo'], 'settings' => ['workspace' => 'acme'],
        ]);
        Connection::factory()->anthropic('sk-ant-demo')->create(['tenant_id' => $tenant->id]);
        Connection::factory()->jira()->create(['tenant_id' => $tenant->id]);

        $repositories = collect([
            ['slug' => 'shop-api', 'review_stack' => ['laravel'], 'review_runner' => 'docker'],
            ['slug' => 'storefront', 'review_stack' => ['magento', 'hyva'], 'review_runner' => 'docker'],
            ['slug' => 'customer-portal', 'review_stack' => ['laravel'], 'review_runner' => 'diff'],
            ['slug' => 'marketing-site', 'review_stack' => ['drupal'], 'review_runner' => 'diff'],
        ])->map(fn (array $attributes): Repository => Repository::query()->create($attributes + [
            'tenant_id' => $tenant->id, 'connection_id' => $bitbucket->id, 'workspace' => 'acme',
            'review_available_providers' => $attributes['review_runner'] === 'docker' ? ['claude-code', 'codex-cli', 'pi'] : null,
            'review_available_at' => $attributes['review_runner'] === 'docker' ? now()->subDay() : null,
        ]));

        $titles = [
            ['correctness', 'Discount is applied twice when the cart is recalculated'],
            ['security', 'Export endpoint returns invoices of other customers'],
            ['security', 'Webhook signature is compared with == instead of hash_equals'],
            ['performance', 'Order list loads every line item per row'],
            ['tests', 'The new refund rule has no test for partial refunds'],
            ['maintainability', 'Shipping rates are copied into three controllers'],
            ['correctness', 'Time zone is dropped when the delivery date is saved'],
            ['performance', 'Product import runs a query per attribute'],
        ];

        $build = 100;

        foreach (range(29, 0) as $daysAgo) {
            foreach (range(1, mt_rand(1, 4)) as $ignored) {
                $repository = $repositories[mt_rand(0, $repositories->count() - 1)];
                $findings = [];

                // Between none and four findings.
                for ($number = 1, $count = mt_rand(0, 4); $number <= $count; $number++) {
                    [$category, $title] = $titles[mt_rand(0, count($titles) - 1)];
                    $findings[] = [
                        'id' => "F{$number}", 'severity' => ['HIGH', 'MEDIUM', 'MEDIUM', 'LOW', 'LOW', 'LOW', 'LOW', 'LOW'][mt_rand(0, 7)], 'status' => 'new',
                        'category' => $category, 'confidence' => mt_rand(6, 10), 'title' => $title,
                        'path' => 'app/Services/'.['Cart', 'Export', 'Orders', 'Refunds'][mt_rand(0, 3)].'.php', 'line' => mt_rand(10, 220),
                    ];
                }

                $failed = collect($findings)->contains(fn (array $finding): bool => $finding['severity'] !== 'LOW');
                $run = $this->review($repository, ++$build, now()->subDays($daysAgo)->setTime(mt_rand(8, 18), mt_rand(0, 59)), [
                    'findings' => $findings,
                    'result' => $failed ? 'FAILED' : 'PASSED',
                    'status' => mt_rand(1, 30) === 1 ? ReviewRun::STATUS_TIMEOUT : ReviewRun::STATUS_FINISHED,
                ]);

                if ($findings !== [] && mt_rand(1, 3) === 1) {
                    ReviewRating::query()->create([
                        'review_run_id' => $run->id, 'score' => mt_rand(3, 5),
                        'findings' => collect($findings)->mapWithKeys(fn (array $finding): array => [$finding['id'] => mt_rand(1, 4) > 1])->all(),
                    ]);
                }
            }
        }

        // The review to show: a pull request with a summary, findings and something left for a person.
        $this->review($repositories[0], ++$build, now()->subMinutes(40), [
            'branch' => 'feature/partial-refunds', 'pull_request_id' => 214, 'result' => 'FAILED',
            'summary' => "Adds partial refunds to the order screen and the API.\n\nThe refund amount is checked against what was paid, but not against what was refunded before, so two partial refunds can add up to more than the order. The new endpoint also skips the policy the other order endpoints use.",
            'manual_review' => ['Check the refund e-mail with the payment provider\'s sandbox: it was not run here.'],
            'findings' => [
                [
                    'id' => 'F1', 'severity' => 'HIGH', 'status' => 'new', 'category' => 'correctness', 'confidence' => 9,
                    'title' => 'Two partial refunds can exceed the amount paid', 'path' => 'app/Services/Refunds.php', 'line' => 58,
                    'details' => 'The check compares the requested amount with the order total, not with what is left after earlier refunds. Two refunds of 60% each both pass.',
                    'prompt' => 'In app/Services/Refunds.php, compare the requested amount with the order total minus the sum of earlier refunds, and add a test for two partial refunds.',
                ],
                [
                    'id' => 'F2', 'severity' => 'MEDIUM', 'status' => 'new', 'category' => 'security', 'confidence' => 8,
                    'title' => 'Refund endpoint does not authorise the order', 'path' => 'app/Http/Controllers/RefundController.php', 'line' => 23,
                    'details' => 'Every other order endpoint calls $this->authorize(\'update\', $order). This one does not, so any signed-in customer can refund any order by its id.',
                    'prompt' => 'In RefundController::store, authorise the order with the OrderPolicy before refunding, and add a test that another customer gets a 403.',
                ],
                [
                    'id' => 'F3', 'severity' => 'LOW', 'status' => 'new', 'category' => 'tests', 'confidence' => 7,
                    'title' => 'No test for a refund on a cancelled order', 'path' => 'tests/Feature/RefundTest.php', 'line' => 12,
                    'details' => 'The ticket says a cancelled order cannot be refunded; nothing tests it.',
                    'prompt' => 'Add a test to tests/Feature/RefundTest.php that refunding a cancelled order is refused.',
                ],
            ],
        ]);

        $this->review($repositories[1], ++$build, now()->subMinutes(3), [
            'branch' => 'feature/hyva-checkout', 'pull_request_id' => 88, 'status' => ReviewRun::STATUS_RUNNING,
            'result' => null, 'finished_at' => null, 'duration_seconds' => null, 'cost' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function review(Repository $repository, int $build, mixed $startedAt, array $attributes): ReviewRun
    {
        // Today's reviews happened before now.
        if ($startedAt->isFuture()) {
            $startedAt = now()->subMinutes(mt_rand(30, 240));
        }
        $duration = mt_rand(70, 420);

        return ReviewRun::query()->create($attributes + [
            'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id,
            'workspace' => $repository->workspace, 'slug' => $repository->slug,
            'branch' => 'feature/'.['refunds', 'checkout', 'export', 'search', 'invoices'][mt_rand(0, 4)],
            'pull_request_id' => mt_rand(20, 240), 'commit' => sha1((string) $build), 'build_number' => $build,
            'status' => ReviewRun::STATUS_FINISHED, 'started_at' => $startedAt, 'finished_at' => $startedAt->copy()->addSeconds($duration),
            'duration_seconds' => $duration, 'queue_seconds' => mt_rand(0, 40),
            'input_tokens' => $input = mt_rand(40_000, 260_000), 'output_tokens' => $output = mt_rand(3_000, 14_000), 'cost' => mt_rand(8, 95) / 100,
            'usage' => ['claude-sonnet-5-5' => [
                'input' => (int) ($input * 0.2), 'output' => $output, 'cache_read' => (int) ($input * 0.7), 'cache_creation' => (int) ($input * 0.1),
            ]],
            'model' => 'claude-sonnet-5-5', 'agent' => $repository->review_runner === 'diff' ? null : 'claude-code', 'provider' => 'anthropic',
            'runner' => $repository->review_runner, 'fail_on' => 'medium', 'rating_token' => Str::random(40),
        ]);
    }
}
