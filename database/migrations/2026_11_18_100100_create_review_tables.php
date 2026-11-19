<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_instructions', function (Blueprint $table) {
            $table->id();
            // Null is the default every tenant reads until it writes its own.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('title');
            $table->string('stack', 32)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->longText('body')->nullable();
            $table->longText('draft_body')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('review_instruction_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 16)->unique();
            $table->json('files');
            $table->timestamps();
        });

        Schema::create('review_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null is the tenant's default.
            $table->foreignId('repository_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->json('providers')->nullable();
            $table->string('claude_model')->nullable();
            $table->string('claude_effort', 16)->nullable();
            $table->string('claude_advisor')->nullable();
            $table->string('claude_max_budget', 16)->nullable();
            $table->string('codex_model')->nullable();
            $table->string('codex_effort', 16)->nullable();
            $table->string('ocr_model')->nullable();
            $table->string('ocr_effort', 16)->nullable();
            $table->string('verify', 8)->nullable();
            $table->timestamps();
        });

        Schema::create('review_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('workspace');
            $table->string('slug');
            $table->string('branch');
            $table->unsignedInteger('pull_request_id')->nullable();
            $table->string('commit', 40);
            $table->string('superseded_by', 40)->nullable();
            $table->string('input_hash', 64)->nullable();
            $table->unsignedInteger('build_number')->nullable();
            $table->string('selector')->nullable();
            $table->string('runner', 32)->nullable();
            $table->string('status', 20)->default('running');
            $table->string('result', 20)->nullable();
            $table->text('summary')->nullable();
            $table->json('findings')->nullable();
            $table->json('context')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->decimal('cost', 10, 4)->nullable();
            $table->boolean('cost_estimated')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('restarted_at')->nullable();
            $table->unsignedInteger('restarted_as')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('model')->nullable();
            $table->json('usage')->nullable();
            $table->string('agent', 32)->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('effort', 20)->nullable();
            $table->string('advisor')->nullable();
            $table->boolean('custom')->default(false);
            $table->string('instructions_version', 16)->nullable();
            $table->string('rating_token', 40)->nullable()->unique();
            $table->timestamps();
            $table->index(['repository_id', 'branch', 'commit']);
            $table->index(['repository_id', 'input_hash', 'status']);
            $table->index(['tenant_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        Schema::create('review_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->json('findings')->nullable();
            $table->timestamps();
        });

        Schema::create('pull_request_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pull_request_id');
            $table->boolean('draft')->default(false);
            $table->string('source_updated_on', 32)->nullable();
            $table->timestamps();
            $table->unique(['repository_id', 'pull_request_id']);
        });

        Schema::create('model_prices', function (Blueprint $table) {
            $table->id();
            $table->string('model')->unique();
            $table->decimal('input', 16, 12)->nullable();
            $table->decimal('output', 16, 12)->nullable();
            $table->decimal('cache_read', 16, 12)->nullable();
            $table->decimal('cache_creation', 16, 12)->nullable();
            $table->string('source')->default('litellm');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['model_prices', 'pull_request_drafts', 'review_ratings', 'review_runs', 'review_settings', 'review_instruction_versions', 'review_instructions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
