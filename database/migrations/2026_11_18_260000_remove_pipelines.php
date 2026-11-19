<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reviews no longer run in a repository's own pipeline: the key a pipeline
     * called with, the switch that required its identity token and the name of the
     * pipeline a run was have nothing left to say.
     */
    public function up(): void
    {
        // A pipeline repository without a key was added and never set up, so it was
        // never reviewed. On a runner this server starts it would be, from the next
        // push on: it stays off until someone turns it on.
        DB::table('repositories')->where('review_runner', 'pipeline')->whereNull('api_key_hash')->update(['reviews_enabled' => false]);

        // Every repository runs on a runner this server starts.
        DB::table('repositories')->where('review_runner', 'pipeline')->update(['review_runner' => 'container']);

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropUnique(['api_key_hash']);
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['api_key_hash', 'api_key_generated_at']);
            $table->string('review_runner', 32)->default('container')->change();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('require_pipeline_token');
        });

        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn('selector');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('api_key_hash', 64)->nullable()->unique();
            $table->timestamp('api_key_generated_at')->nullable();
            $table->string('review_runner', 32)->default('pipeline')->change();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('require_pipeline_token')->default(false);
        });

        Schema::table('review_runs', function (Blueprint $table) {
            $table->string('selector')->nullable();
        });
    }
};
