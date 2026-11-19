<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            // The most memory the reviewer's container was seen to use, in megabytes:
            // what its limit can be set from.
            $table->unsignedInteger('peak_memory_mb')->nullable()->after('queue_seconds');
            // The limit that container had, as Docker was given it ("4g").
            $table->string('memory_limit', 16)->nullable()->after('peak_memory_mb');
        });
    }

    public function down(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn(['peak_memory_mb', 'memory_limit']);
        });
    }
};
