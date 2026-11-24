<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the lookups the pages make most: the reviews of one pull request,
 * newest first, and a tenant's open findings and unanswered questions, which the
 * menu badges count on every page. SQLite does not index a foreign key by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->index(['repository_id', 'pull_request_id', 'started_at']);
        });

        Schema::table('open_findings', function (Blueprint $table) {
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('review_questions', function (Blueprint $table) {
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropIndex(['repository_id', 'pull_request_id', 'started_at']);
        });

        Schema::table('open_findings', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
        });

        Schema::table('review_questions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
        });
    }
};
