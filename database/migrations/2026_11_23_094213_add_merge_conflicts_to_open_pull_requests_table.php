<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_pull_requests', function (Blueprint $table) {
            // Whether it can be merged as it stands, as its host said when last asked;
            // null when it was not asked yet or could not say.
            $table->boolean('merge_conflicts')->nullable();
            $table->json('conflicting_files')->nullable();
            $table->timestamp('merge_checked_at')->nullable();
            // The head it was asked for: after a push the answer is of another commit.
            $table->string('merge_checked_commit', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('open_pull_requests', function (Blueprint $table) {
            $table->dropColumn(['merge_conflicts', 'conflicting_files', 'merge_checked_at', 'merge_checked_commit']);
        });
    }
};
