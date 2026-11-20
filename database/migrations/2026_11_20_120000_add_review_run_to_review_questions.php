<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The review a question is about, so its answer starts from that review's
 * findings, and not only from a session that may not be kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_questions', function (Blueprint $table) {
            $table->foreignId('review_run_id')->nullable()->after('repository_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('review_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_run_id');
        });
    }
};
