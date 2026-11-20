<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a review's steps took and what its reviewer used: how long each step ran,
 * and the processors and memory it was seen to use, for choosing the size it runs in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->json('metrics')->nullable()->after('memory_limit');
        });
    }

    public function down(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn('metrics');
        });
    }
};
