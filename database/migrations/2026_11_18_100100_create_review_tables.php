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
            // The hash of the shipped text a default was imported from, or a platform
            // copy was made from: what tells an edit here from an older release's
            // text, so an update offers the new text without overwriting someone's
            // own words.
            $table->string('shipped_hash', 64)->nullable();
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
            // Reviews a container job carries on with when the pull request got a
            // new commit meanwhile; null reads the tenant's default.
            $table->unsignedTinyInteger('follow_ups')->nullable();
            // What a team may want otherwise than the review's own text says: the
            // language it writes in, whether it grades the change against the
            // ticket, and whether it runs the extra passes that cost more.
            $table->string('language', 8)->nullable();
            $table->string('ticket_grading', 5)->nullable();
            $table->string('extra_passes', 5)->nullable();
            $table->timestamps();
        });

        Schema::create('review_runs', function (Blueprint $table) {
            $table->id();
            // What a run's address is made of, so a link to one says nothing about
            // how many there are.
            $table->uuid('uuid')->unique();
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
            // docker, cloudflare or diff.
            $table->string('runner', 32)->nullable();
            $table->string('status', 20)->default('running');
            $table->string('result', 20)->nullable();
            // The threshold the review's result was worked out with, so its page
            // marks the same findings as holding the merge.
            $table->string('fail_on', 200)->nullable();
            $table->text('summary')->nullable();
            $table->json('findings')->nullable();
            // What the review left for a person to check. With any, a review that
            // passes does not approve the pull request.
            $table->json('manual_review')->nullable();
            $table->json('context')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->decimal('cost', 10, 4)->nullable();
            $table->boolean('cost_estimated')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            // A review that did not finish and that someone has looked at: it leaves
            // the lists of what needs action, and stays in the figures.
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('restarted_at')->nullable();
            $table->unsignedInteger('restarted_as')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            // Seconds a container review waited for a worker before it started.
            $table->unsignedInteger('queue_seconds')->nullable();
            // The most memory the reviewer's container was seen to use, in
            // megabytes, and the limit it had, as Docker was given it ("4g").
            $table->unsignedInteger('peak_memory_mb')->nullable();
            $table->string('memory_limit', 16)->nullable();
            // Where the reviewer ran when that was not Docker on the worker, how
            // long that sandbox ran and what it cost in dollars, as estimated from
            // its size and the rates in the configuration at the time.
            $table->string('sandbox', 20)->nullable();
            $table->string('sandbox_size', 20)->nullable();
            $table->unsignedInteger('sandbox_seconds')->nullable();
            $table->decimal('sandbox_cost', 10, 4)->nullable();
            $table->string('model')->nullable();
            $table->json('usage')->nullable();
            $table->string('agent', 32)->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('effort', 20)->nullable();
            $table->string('advisor')->nullable();
            $table->boolean('custom')->default(false);
            // What was reviewed: a pull request, or a branch as it stands, for its
            // security. A branch's review has no pull request to post on.
            $table->string('kind', 20)->default('pull-request');
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

        // What someone thought of one finding of one run: right, not a problem, or wrong.
        Schema::create('finding_verdicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('finding_id', 64);
            $table->string('verdict', 20);
            $table->timestamps();
            $table->unique(['review_run_id', 'user_id', 'finding_id']);
        });

        // A question someone asked the reviewer, in a comment on a pull request or
        // on a review's page, and what it answered. Kept for what it cost and to
        // show beside the reviews.
        Schema::create('review_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('pull_request_id');
            $table->string('branch');
            // The comment that asked, which the answer is a reply to; none for a
            // question asked on a review's page, which is answered there alone.
            $table->unsignedBigInteger('comment_id')->nullable();
            // The reviewer's own reply, which a comment under it follows up on.
            $table->unsignedBigInteger('answer_comment_id')->nullable();
            // The question this one follows up on, whose answer it was a reply to.
            $table->unsignedBigInteger('follows_id')->nullable();
            $table->string('author')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            // What a question asked under one of the review's findings is about:
            // that finding, as the review posted it, for the reviewer to answer from.
            $table->text('about')->nullable();
            $table->text('answer')->nullable();
            $table->string('status', 20)->default('asked');
            // Whether the answer came from the session of the last review.
            $table->boolean('resumed')->default(false);
            $table->string('model')->nullable();
            $table->decimal('cost', 10, 4)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            // One answer per comment, however often the host delivers it.
            $table->unique(['repository_id', 'comment_id']);
            $table->index(['repository_id', 'pull_request_id']);
            $table->index(['repository_id', 'answer_comment_id']);
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

        // Costs are stored in dollars, as the model prices are; this is what one
        // dollar is worth in each other currency a tenant can read them in.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->string('currency', 3)->primary();
            $table->decimal('rate', 12, 6);
            $table->timestamp('fetched_at');
        });

        // What the installation was doing each minute, to tell whether it has room.
        Schema::create('capacity_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('sampled_at')->index();
            $table->unsignedSmallInteger('running');
            // Of the reviews running, how many had their reviewer in a Cloudflare
            // sandbox: a worker each, and next to nothing of this server's
            // processor and memory.
            $table->unsignedSmallInteger('running_sandboxes')->default(0);
            // Reviews waiting for a worker, not counting the ones running.
            $table->unsignedSmallInteger('waiting');
            // Reviews held until another review of the same pull request is done.
            // They wait for that review, not for a worker.
            $table->unsignedSmallInteger('held')->default(0);
            $table->unsignedSmallInteger('workers');
            $table->decimal('load', 6, 2)->nullable();
            $table->unsignedSmallInteger('cpus')->nullable();
            $table->decimal('memory_used_percent', 5, 2)->nullable();
            $table->decimal('disk_used_percent', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['capacity_samples', 'exchange_rates', 'model_prices', 'pull_request_drafts', 'review_questions', 'finding_verdicts', 'review_ratings', 'review_runs', 'review_settings', 'review_instruction_versions', 'review_instructions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
