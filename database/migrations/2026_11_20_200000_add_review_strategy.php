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
            // Off, record or apply; null inherits, and off where nothing sets it.
            $table->string('strategy', 8)->nullable();
            // The lowest effort and model tier a classified pull request runs at.
            $table->string('strategy_effort', 16)->nullable();
            $table->string('strategy_model', 16)->nullable();
        });

        // What a commit of a pull request was classified as, once per commit, so
        // every build of it runs at the same effort and model.
        Schema::create('review_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pull_request_id');
            $table->string('commit', 40);
            $table->string('complexity', 8);
            $table->string('kind', 16)->nullable();
            $table->json('flags');
            $table->text('reason')->nullable();
            $table->unsignedInteger('files_count');
            $table->unsignedInteger('lines_added');
            $table->unsignedInteger('lines_removed');
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost', 10, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'commit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_classifications');

        Schema::table('review_settings', function (Blueprint $table) {
            $table->dropColumn(['strategy', 'strategy_effort', 'strategy_model']);
        });
    }
};
