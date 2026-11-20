<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a pull request's last review still had open when it was merged: a
        // backlog per repository, checked now and then against the main branch.
        Schema::create('open_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pull_request_id');
            $table->foreignId('review_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('finding_id', 16);
            $table->string('severity', 16)->nullable();
            $table->string('title');
            $table->string('path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('details')->nullable();
            $table->string('merge_commit', 40)->nullable();
            // open, present, resolved, unclear or dismissed.
            $table->string('status', 16)->default('open');
            $table->text('note')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('checked_by_model')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'pull_request_id', 'finding_id']);
            $table->index(['repository_id', 'status']);
        });

        Schema::table('review_settings', function (Blueprint $table) {
            // Off, weekly or monthly: how often the open findings are checked.
            $table->string('findings_check', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_findings');

        Schema::table('review_settings', function (Blueprint $table) {
            $table->dropColumn('findings_check');
        });
    }
};
