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

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('require_pipeline_token')->default(false);
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
            $table->string('api_key_hash', 64)->nullable()->unique();
            $table->timestamp('api_key_generated_at')->nullable();
            $table->string('review_runner', 32)->default('pipeline');
            $table->string('review_instructions_channel', 16)->default('stable');
            $table->json('review_stack')->nullable();
            $table->json('review_available_providers')->nullable();
            $table->timestamp('review_available_at')->nullable();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sources');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
