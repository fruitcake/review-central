<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            // "true" or "false" each, as verify and extra_passes; null inherits.
            $table->string('ocr', 5)->nullable();
            $table->string('scan', 5)->nullable();
            $table->string('floor_guard', 5)->nullable();
        });

        Schema::table('review_runs', function (Blueprint $table) {
            // Which parts of the review were on, so two runs can be compared.
            $table->json('modules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            $table->dropColumn(['ocr', 'scan', 'floor_guard']);
        });

        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
