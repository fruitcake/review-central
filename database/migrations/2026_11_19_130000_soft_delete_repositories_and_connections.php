<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A removed repository, and a removed source control connection, are kept out of
 * sight rather than deleted: their reviews, questions and pull requests are the
 * team's history, and adding the repository again brings it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
