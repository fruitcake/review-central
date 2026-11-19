<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Dollars a month the tenant means to spend on reviews; nothing stops at it.
            $table->decimal('monthly_budget', 10, 2)->nullable()->after('require_pipeline_token');
        });

        Schema::table('review_runs', function (Blueprint $table) {
            // Seconds a container review waited for a worker before it started.
            $table->unsignedInteger('queue_seconds')->nullable()->after('duration_seconds');
        });

        // What the installation was doing each minute, to tell whether it has room.
        Schema::create('capacity_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('sampled_at')->index();
            $table->unsignedSmallInteger('running');
            $table->unsignedSmallInteger('waiting');
            $table->unsignedSmallInteger('workers');
            $table->decimal('load', 6, 2)->nullable();
            $table->unsignedSmallInteger('cpus')->nullable();
            $table->decimal('memory_used_percent', 5, 2)->nullable();
            $table->decimal('disk_used_percent', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_samples');

        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn('queue_seconds');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('monthly_budget');
        });
    }
};
