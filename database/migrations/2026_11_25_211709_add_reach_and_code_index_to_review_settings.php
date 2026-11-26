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
            // "true" or "false", as the other parts of the review; null inherits. REACH is on
            // by default, the code index it can follow off.
            $table->string('reach', 5)->nullable()->after('floor_guard');
            $table->string('code_index', 5)->nullable()->after('reach');
        });
    }

    public function down(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            $table->dropColumn(['reach', 'code_index']);
        });
    }
};
