<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Of the reviews running, how many had their reviewer in a Cloudflare sandbox:
     * a worker each, and next to nothing of this server's processor and memory.
     */
    public function up(): void
    {
        Schema::table('capacity_samples', function (Blueprint $table) {
            $table->unsignedSmallInteger('running_sandboxes')->default(0)->after('running');
        });
    }

    public function down(): void
    {
        Schema::table('capacity_samples', function (Blueprint $table) {
            $table->dropColumn('running_sandboxes');
        });
    }
};
