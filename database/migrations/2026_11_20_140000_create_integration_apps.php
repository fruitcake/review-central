<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The apps this installation, or a tenant, is registered as at Google, Asana,
 * Atlassian and GitHub: a list rather than one of each. What was set under
 * Settings → Integrations becomes the platform's apps, each for the tenants that
 * had it on.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> type => credential => the setting it was kept under */
    protected const FROM_SETTINGS = [
        'google' => ['client_id' => 'google_client_id', 'client_secret' => 'google_client_secret'],
        'asana' => ['client_id' => 'asana_client_id', 'client_secret' => 'asana_client_secret'],
        'atlassian' => ['client_id' => 'atlassian_client_id', 'client_secret' => 'atlassian_client_secret'],
        'github_app' => [
            'app_id' => 'github_app_id', 'slug' => 'github_app_slug', 'client_id' => 'github_app_client_id',
            'client_secret' => 'github_app_client_secret', 'private_key' => 'github_app_private_key', 'webhook_secret' => 'github_app_webhook_secret',
        ],
    ];

    protected const SECRETS = ['client_secret', 'private_key', 'webhook_secret'];

    /** What a tenant could turn off, by the type of app. */
    protected const TURNED_OFF = ['asana' => 'asana', 'atlassian' => 'jira', 'github_app' => 'github_app'];

    protected const NAMES = ['google' => 'Google', 'asana' => 'Asana', 'atlassian' => 'Atlassian', 'github_app' => 'GitHub App'];

    public function up(): void
    {
        Schema::create('integration_apps', function (Blueprint $table) {
            $table->id();
            // The tenant that added it, for itself alone; null for one of the platform's.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('name');
            $table->text('credentials');
            // A platform's app: for every tenant, or only those listed.
            $table->boolean('for_all_tenants')->default(false);
            $table->timestamps();
            $table->index(['type', 'tenant_id']);
        });

        Schema::create('integration_app_tenant', function (Blueprint $table) {
            $table->foreignId('integration_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->primary(['integration_app_id', 'tenant_id']);
        });

        $this->moveFromSettings();

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('integrations_off');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('integrations_off')->nullable()->after('sign_in_domains');
        });

        Schema::dropIfExists('integration_app_tenant');
        Schema::dropIfExists('integration_apps');
    }

    /**
     * Each app set up under Settings as an app of the platform's, for the tenants
     * that had not turned it off; and those settings gone.
     */
    protected function moveFromSettings(): void
    {
        $settings = DB::table('platform_settings')->pluck('value', 'key')
            ->map(fn (mixed $value): mixed => is_string($value) ? json_decode($value, true) : $value);
        $tenants = DB::table('tenants')->get(['id', 'integrations_off']);

        foreach (self::FROM_SETTINGS as $type => $fields) {
            $credentials = [];

            foreach ($fields as $credential => $setting) {
                $value = $settings[$setting] ?? null;

                if (is_string($value) && $value !== '' && in_array($credential, self::SECRETS, true)) {
                    $value = rescue(fn (): string => Crypt::decryptString($value), null, false);
                }

                if (is_string($value) && $value !== '') {
                    $credentials[$credential] = $value;
                }
            }

            if ($credentials !== []) {
                $off = $tenants->filter(fn (object $tenant): bool => in_array(self::TURNED_OFF[$type] ?? null, (array) json_decode((string) $tenant->integrations_off, true), true));
                $id = DB::table('integration_apps')->insertGetId([
                    'tenant_id' => null,
                    'type' => $type,
                    'name' => self::NAMES[$type],
                    'credentials' => Crypt::encryptString((string) json_encode($credentials)),
                    // As it was: on for every tenant, unless one turned it off.
                    'for_all_tenants' => $type === 'google' || $off->isEmpty(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($type !== 'google' && $off->isNotEmpty()) {
                    DB::table('integration_app_tenant')->insert($tenants->diffKeys($off)->map(fn (object $tenant): array => ['integration_app_id' => $id, 'tenant_id' => $tenant->id])->values()->all());
                }
            }

            DB::table('platform_settings')->whereIn('key', array_values($fields))->delete();
        }

        // The settings are kept in the cache as well.
        Cache::forget('platform-settings');
    }
};
