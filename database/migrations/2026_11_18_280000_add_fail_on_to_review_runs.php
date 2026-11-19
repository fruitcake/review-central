<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            // The threshold the review's result was worked out with, so its page
            // marks the same findings as holding the merge. Null on a run from
            // before this, which is read as the default.
            $table->string('fail_on', 200)->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn('fail_on');
        });
    }
};
