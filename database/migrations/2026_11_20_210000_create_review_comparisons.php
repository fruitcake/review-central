<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reviews of one pull request with different setups, side by side, and what
        // a model made of them.
        Schema::create('review_comparisons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pull_request_id');
            // The review it was started from, which is the first column.
            $table->foreignId('review_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Each setup tried, with the build its trial runs as.
            $table->json('rows');
            $table->string('analysis_status', 16)->nullable();
            $table->string('analysis_model')->nullable();
            $table->json('analysis')->nullable();
            $table->text('analysis_error')->nullable();
            $table->decimal('analysis_cost', 10, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_comparisons');
    }
};
