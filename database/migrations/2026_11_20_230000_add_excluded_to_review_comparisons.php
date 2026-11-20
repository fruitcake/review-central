<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_comparisons', function (Blueprint $table) {
            // The columns left out, by label: shown aside, and not analysed.
            $table->json('excluded')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('review_comparisons', function (Blueprint $table) {
            $table->dropColumn('excluded');
        });
    }
};
