<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A question asked on a review's page has no comment on the pull request,
     * and is answered on the page alone. It says who asked.
     */
    public function up(): void
    {
        Schema::table('review_questions', function (Blueprint $table) {
            $table->unsignedBigInteger('comment_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->after('author')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('review_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
