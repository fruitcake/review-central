<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            // Off for a repository that is only here to try a review on: nothing
            // starts a review of it but a person.
            $table->boolean('reviews_enabled')->default(true)->after('review_runner');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('reviews_enabled');
        });
    }
};
