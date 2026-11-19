<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a question asked under one of the review's findings is about: that
     * finding, as the review posted it, for the reviewer to answer from.
     */
    public function up(): void
    {
        Schema::table('review_questions', function (Blueprint $table) {
            $table->text('about')->nullable()->after('question');
        });
    }

    public function down(): void
    {
        Schema::table('review_questions', function (Blueprint $table) {
            $table->dropColumn('about');
        });
    }
};
