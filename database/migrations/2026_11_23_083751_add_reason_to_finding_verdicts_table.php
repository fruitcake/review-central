<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finding_verdicts', function (Blueprint $table) {
            // Why a finding was dismissed, for the statistics; null for one judged valid.
            $table->string('reason', 32)->nullable()->after('verdict');
            $table->text('note')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('finding_verdicts', function (Blueprint $table) {
            $table->dropColumn(['reason', 'note']);
        });
    }
};
