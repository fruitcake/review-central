<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A question someone asked the reviewer in a comment on a pull request, and
        // what it answered. Kept for what it cost and to show beside the reviews.
        Schema::create('review_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('pull_request_id');
            $table->string('branch');
            // The comment that asked, which the answer is a reply to.
            $table->unsignedBigInteger('comment_id');
            $table->string('author')->nullable();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->string('status', 20)->default('asked');
            // Whether the answer came from the session of the last review.
            $table->boolean('resumed')->default(false);
            $table->string('model')->nullable();
            $table->decimal('cost', 10, 4)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            // One answer per comment, however often the host delivers it.
            $table->unique(['repository_id', 'comment_id']);
            $table->index(['repository_id', 'pull_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_questions');
    }
};
