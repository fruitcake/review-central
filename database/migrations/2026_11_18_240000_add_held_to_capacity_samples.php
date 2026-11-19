<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capacity_samples', function (Blueprint $table) {
            // Reviews held until another review of the same pull request is done.
            // They wait for that review, not for a worker.
            $table->unsignedSmallInteger('held')->default(0)->after('waiting');
        });

        // `waiting` was the whole queue, which counts the reviews a worker is running
        // too: every running review read as one waiting. Taken off what is stored, as
        // near as it can be told afterwards.
        DB::table('capacity_samples')->update([
            'waiting' => DB::raw('case when waiting > running then waiting - running else 0 end'),
        ]);
    }

    public function down(): void
    {
        Schema::table('capacity_samples', function (Blueprint $table) {
            $table->dropColumn('held');
        });
    }
};
