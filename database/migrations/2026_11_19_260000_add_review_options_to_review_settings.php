<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a team may want otherwise than the review's own text says: the
     * language it writes in, whether it grades the change against the ticket, and
     * whether it runs the extra passes that cost more.
     */
    public function up(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            $table->string('language', 8)->nullable()->after('follow_ups');
            $table->string('ticket_grading', 5)->nullable()->after('language');
            $table->string('extra_passes', 5)->nullable()->after('ticket_grading');
        });
    }

    public function down(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            $table->dropColumn(['language', 'ticket_grading', 'extra_passes']);
        });
    }
};
