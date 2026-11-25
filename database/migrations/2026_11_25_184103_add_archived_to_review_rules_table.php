<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A rule the repository now holds in a rule file of its own, on its main branch.
        Schema::table('review_rules', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('enabled');
            $table->string('archived_to')->nullable()->after('archived_at');
            $table->string('archived_note', 500)->nullable()->after('archived_to');
        });
    }

    public function down(): void
    {
        Schema::table('review_rules', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'archived_to', 'archived_note']);
        });
    }
};
