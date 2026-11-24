<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When someone last started the pull request's review by hand, so a draft is
 * reviewed then, as on a /review comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_pull_requests', function (Blueprint $table) {
            $table->timestamp('review_requested_at')->nullable()->after('awaiting_approval');
        });
    }

    public function down(): void
    {
        Schema::table('open_pull_requests', function (Blueprint $table) {
            $table->dropColumn('review_requested_at');
        });
    }
};
