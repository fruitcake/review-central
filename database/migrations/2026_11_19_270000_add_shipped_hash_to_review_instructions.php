<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hash of the shipped text a default was imported from, or a platform copy
     * was made from: what tells an edit here from an older release's text, so an
     * update offers the new text without overwriting someone's own words.
     */
    public function up(): void
    {
        Schema::table('review_instructions', function (Blueprint $table) {
            $table->string('shipped_hash', 64)->nullable()->after('body');
        });

        // A default that is this release's text is known to be untouched. One that
        // is not may be an older release's or someone's edit; that cannot be told
        // now, so an update shows it as edited and leaves it unpicked.
        foreach (File::glob(resource_path('review/skill/references').'/*.md') as $path) {
            $body = File::get($path);

            DB::table('review_instructions')
                ->whereNull('tenant_id')
                ->where('key', basename($path, '.md'))
                ->where('body', $body)
                ->update(['shipped_hash' => hash('sha256', $body)]);
        }
    }

    public function down(): void
    {
        Schema::table('review_instructions', function (Blueprint $table) {
            $table->dropColumn('shipped_hash');
        });
    }
};
