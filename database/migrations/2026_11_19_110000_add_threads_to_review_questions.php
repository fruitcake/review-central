<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_questions', function (Blueprint $table) {
            // The reviewer's own reply, which a comment under it follows up on.
            $table->unsignedBigInteger('answer_comment_id')->nullable()->after('comment_id');
            // The question this one follows up on, whose answer it was a reply to.
            $table->unsignedBigInteger('follows_id')->nullable()->after('answer_comment_id');
            $table->index(['repository_id', 'answer_comment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('review_questions', function (Blueprint $table) {
            $table->dropIndex(['repository_id', 'answer_comment_id']);
            $table->dropColumn(['answer_comment_id', 'follows_id']);
        });
    }
};
