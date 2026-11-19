<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReviewInstruction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\ReviewInstructions;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class Install extends Command
{
    protected $signature = 'review:install';

    protected $description = 'Set up a new installation: the default review instructions, the model prices and the first administrator';

    /**
     * Safe to run again: each step is skipped once it has been done.
     */
    public function handle(): int
    {
        if (ReviewInstruction::query()->whereNull('tenant_id')->doesntExist()) {
            $result = ReviewInstructions::import(resource_path(ReviewInstructions::BUNDLED_DIRECTORY));

            $this->info('Imported '.count($result['created']).' default review instruction(s).');
        } else {
            $this->line('The default review instructions are already there.');
        }

        // Best effort: without the prices a run's cost stays empty until the daily sync.
        if ($this->call('review:sync-model-prices') !== self::SUCCESS) {
            $this->warn('The model prices could not be fetched now; the daily sync tries again.');
        }

        // Best effort too: a tenant reads dollars until there is a rate.
        if ($this->call('review:sync-exchange-rates') !== self::SUCCESS) {
            $this->warn('The exchange rates could not be fetched now; the daily sync tries again.');
        }

        if (User::query()->where('is_admin', true)->exists()) {
            $this->line('There is an administrator already.');

            return self::SUCCESS;
        }

        $user = User::query()->create([
            'name' => text('Name of the first administrator', required: true),
            'email' => text('Email address', required: true, validate: ['email' => 'email|unique:users,email']),
            'password' => password('Password', required: true, validate: ['password' => 'min:12']),
            'is_admin' => true,
        ]);

        $this->info("Created the administrator {$user->email}.");

        // A tenant to start in, so the first review is a few clicks away.
        if (Tenant::query()->doesntExist()) {
            $name = text('Name of the first tenant, the team whose repositories are reviewed', default: 'My team', required: true);
            $tenant = Tenant::query()->create([
                'name' => $name, 'slug' => Str::slug($name) ?: 'team',
                'currency' => app(PlatformSettings::class)->currency(), 'timezone' => app(PlatformSettings::class)->timezone(),
            ]);
            $user->tenants()->attach($tenant);

            $this->info("Created the tenant {$tenant->name}. Sign in at ".url("/app/{$tenant->slug}").' to add an AI provider and try a pull request.');

            return self::SUCCESS;
        }

        $this->info('Sign in at '.url('/admin').' to add a tenant.');

        return self::SUCCESS;
    }
}
