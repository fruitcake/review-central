<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a small model writes, for the pull request overview, what the one at
 * turn has to do, and with which model: a team's own choice, under its review
 * defaults, rather than only the installation's in .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            $table->string('turn_notes', 5)->nullable()->after('extra_passes');
            $table->string('turn_model')->nullable()->after('turn_notes');
        });
    }

    public function down(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            $table->dropColumn(['turn_notes', 'turn_model']);
        });
    }
};
