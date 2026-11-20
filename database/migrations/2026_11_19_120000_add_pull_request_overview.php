<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every open pull request the webhook told of, reviewed or not, for the overview a
 * person starts a review from; and whose pull requests a repository reviews by
 * itself: everyone's, or only the trusted accounts'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('pull_request_drafts', 'open_pull_requests');

        Schema::table('open_pull_requests', function (Blueprint $table) {
            $table->string('title')->nullable()->after('pull_request_id');
            $table->string('author')->nullable()->after('title');
            $table->string('author_id')->nullable()->after('author');
            $table->string('author_nickname')->nullable()->after('author_id');
            $table->string('branch')->nullable()->after('author_nickname');
            $table->string('commit', 64)->nullable()->after('branch');
            $table->boolean('from_fork')->default(false)->after('draft');
            // Not started by itself: it waits for someone to start it in the overview.
            $table->boolean('awaiting_approval')->default(false)->after('from_fork');
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->string('reviews_from', 16)->default('everyone')->after('reviews_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('reviews_from');
        });

        Schema::table('open_pull_requests', function (Blueprint $table) {
            $table->dropColumn(['title', 'author', 'author_id', 'author_nickname', 'branch', 'commit', 'from_fork', 'awaiting_approval']);
        });

        Schema::rename('open_pull_requests', 'pull_request_drafts');
    }
};
