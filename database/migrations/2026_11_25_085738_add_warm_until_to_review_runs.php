<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Until when the sandbox a review left stays for the next review of its branch,
 * so the reviews page can show it is still there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->timestamp('warm_until')->nullable()->after('sandbox_cost');
        });
    }

    public function down(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn('warm_until');
        });
    }
};
