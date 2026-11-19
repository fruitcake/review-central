<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            // What was reviewed: a pull request, or a branch as it stands, for
            // its security. A branch's review has no pull request to post on.
            $table->string('kind', 20)->default('pull-request')->after('custom');
        });
    }

    public function down(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
