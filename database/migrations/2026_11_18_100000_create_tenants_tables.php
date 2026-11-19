<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        // Who a user is at a provider they sign in with, such as Google: its own
        // id for them, which stays when their address there changes.
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('provider_id');
            $table->timestamps();
            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // What the tenant means to spend on reviews a month, in its currency;
            // nothing stops at it.
            $table->decimal('monthly_budget', 10, 2)->nullable();
            // What the tenant reads its costs in, and what its monthly budget is in.
            $table->string('currency', 3)->default('USD');
            // The time zone the tenant reads its times in, and where its days begin.
            // Times are stored in UTC.
            $table->string('timezone', 64)->default('UTC');
            // The colours the tenant's panel wears over the installation's: ink,
            // accent and button, each a hex colour or left out.
            $table->json('theme_colors')->nullable();
            // The organisations' domains whose people may join this tenant by
            // signing in, without an invitation: ["example.com"].
            $table->json('sign_in_domains')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('driver', 32);
            $table->string('name');
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->string('webhook_key', 40)->nullable()->unique();
            $table->text('webhook_secret')->nullable();
            // When the host last delivered a webhook that carried the right signature.
            $table->timestamp('webhook_received_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'kind']);
        });

        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->restrictOnDelete();
            $table->string('workspace');
            $table->string('slug');
            $table->string('name')->nullable();
            // docker, cloudflare or diff; null is the platform's runner.
            $table->string('review_runner', 32)->nullable();
            // Off for a repository that is only here to try a review on: nothing
            // starts a review of it but a person.
            $table->boolean('reviews_enabled')->default(true);
            $table->json('review_stack')->nullable();
            $table->json('review_available_providers')->nullable();
            $table->timestamp('review_available_at')->nullable();
            // The same as on the connection, for a delivery about this repository: a
            // webhook on another repository of the connection says nothing about this one.
            $table->timestamp('webhook_received_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'workspace', 'slug']);
        });

        Schema::create('ticket_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('style', 16);
            $table->json('prefixes')->nullable();
            $table->string('reference_prefix', 32)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // What holds for the whole installation and is set in the admin panel, one
        // row per setting.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('ticket_sources');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('user_identities');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
