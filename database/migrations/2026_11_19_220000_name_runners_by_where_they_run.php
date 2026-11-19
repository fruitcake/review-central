<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A runner is now where a review runs: docker, cloudflare or diff. The one
     * runner called "container" ran on Docker or in a Cloudflare sandbox, which
     * each run noted as its sandbox.
     */
    public function up(): void
    {
        // Nothing chosen is the platform's runner.
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('review_runner', 32)->nullable()->default(null)->change();
        });

        // A repository on "container" follows the platform's runner from now on.
        DB::table('repositories')->where('review_runner', 'container')->update(['review_runner' => null]);

        DB::table('review_runs')->where('runner', 'container')->where('sandbox', 'cloudflare')->update(['runner' => 'cloudflare']);
        DB::table('review_runs')->where('runner', 'container')->update(['runner' => 'docker']);
    }

    public function down(): void
    {
        DB::table('review_runs')->whereIn('runner', ['docker', 'cloudflare'])->update(['runner' => 'container']);
        DB::table('repositories')->whereNull('review_runner')->update(['review_runner' => 'container']);

        Schema::table('repositories', function (Blueprint $table) {
            $table->string('review_runner', 32)->default('container')->change();
        });
    }
};
