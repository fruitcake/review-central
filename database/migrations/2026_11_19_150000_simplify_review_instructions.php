<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One file of guidance for a pull request's review instead of two, and no
     * drafts or channels: what is saved is what the next review reads.
     */
    public function up(): void
    {
        // A file that was only ever a draft was never read by a review, and is no
        // method to put lenses under: its tenant read the default one. First, so
        // nothing below takes such a row for a text.
        DB::table('review_instructions')->where(fn ($query) => $query->whereNull('body')->orWhere('body', ''))->delete();

        // The defect lenses become the last section of the method, for the
        // defaults and for each tenant that keeps its own copy of either.
        $lenses = DB::table('review_instructions')->where('key', 'defect-lenses')->get();
        // As it reads before any lenses are added to it: a tenant with lenses of
        // its own gets those under it, not the default ones as well.
        $default = DB::table('review_instructions')->where('key', 'method')->whereNull('tenant_id')->first();

        foreach ($lenses as $lens) {
            $scope = fn () => DB::table('review_instructions')->where('key', 'method')
                ->when($lens->tenant_id === null, fn ($query) => $query->whereNull('tenant_id'), fn ($query) => $query->where('tenant_id', $lens->tenant_id));
            $method = $scope()->first();
            $section = "\n\nDEFECT LENSES:\n\n".trim((string) preg_replace('/^# .*\n/', '', (string) $lens->body));

            if (trim((string) $lens->body) === '') {
                // Nothing published under it: nothing to carry over.
            } elseif ($method !== null) {
                $scope()->update(['body' => rtrim((string) $method->body).$section, 'updated_at' => now()]);
            } else {
                // A tenant with its own lenses and the default method: its own copy of both, as one.
                if ($default !== null) {
                    DB::table('review_instructions')->insert([
                        'tenant_id' => $lens->tenant_id, 'key' => 'method', 'title' => $default->title, 'stack' => null, 'position' => $default->position,
                        'body' => rtrim((string) $default->body).$section, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        // A tenant that kept its own method read the default lenses beside it:
        // those go under its method too, or it would be left with none.
        $defaults = $lenses->firstWhere('tenant_id', null);

        if ($defaults !== null && trim((string) $defaults->body) !== '') {
            $section = "\n\nDEFECT LENSES:\n\n".trim((string) preg_replace('/^# .*\n/', '', (string) $defaults->body));
            $withOwnLenses = $lenses->pluck('tenant_id')->filter()->all();

            DB::table('review_instructions')->where('key', 'method')->whereNotNull('tenant_id')->whereNotIn('tenant_id', $withOwnLenses)->get()
                ->each(fn (object $method) => DB::table('review_instructions')->where('id', $method->id)->update(['body' => rtrim((string) $method->body).$section, 'updated_at' => now()]));
        }

        DB::table('review_instructions')->where('key', 'defect-lenses')->delete();

        Schema::table('review_instructions', function (Blueprint $table) {
            $table->dropColumn('draft_body');
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('review_instructions_channel');
        });
    }

    public function down(): void
    {
        Schema::table('review_instructions', function (Blueprint $table) {
            $table->longText('draft_body')->nullable();
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->string('review_instructions_channel', 16)->default('stable');
        });
    }
};
