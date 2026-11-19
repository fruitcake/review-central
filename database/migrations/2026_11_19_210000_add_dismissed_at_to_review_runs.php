<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A review that did not finish and that someone has looked at: it leaves the
        // lists of what needs action, and stays in the figures.
        Schema::table('review_runs', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
