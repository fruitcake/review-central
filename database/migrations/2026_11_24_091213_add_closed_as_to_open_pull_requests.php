<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pull request that was merged or declined stays listed for a while, with how
 * it was closed and when, instead of being removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_pull_requests', function (Blueprint $table) {
            $table->string('closed_as', 10)->nullable()->after('awaiting_approval');
            $table->timestamp('closed_at')->nullable()->after('closed_as');
            $table->index(['closed_as', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('open_pull_requests', function (Blueprint $table) {
            $table->dropIndex(['closed_as', 'closed_at']);
            $table->dropColumn(['closed_as', 'closed_at']);
        });
    }
};
