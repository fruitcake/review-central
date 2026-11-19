<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            // Reviews a container job carries on with when the pull request got a
            // new commit meanwhile; null reads the tenant's default.
            $table->unsignedTinyInteger('follow_ups')->nullable()->after('verify');
        });
    }

    public function down(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            $table->dropColumn('follow_ups');
        });
    }
};
