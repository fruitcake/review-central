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
            // The model Claude's subagents run on; null inherits, and none set means no subagents.
            $table->string('claude_subagent_model')->nullable()->after('claude_advisor');
        });
    }

    public function down(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            $table->dropColumn('claude_subagent_model');
        });
    }
};
