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
            // "true" or "false", as the parts of the review; null inherits. Off by default:
            // an answer then searches the web, never fetches a page.
            $table->string('question_web_search', 5)->nullable()->after('code_index');
        });
    }

    public function down(): void
    {
        Schema::table('review_settings', function (Blueprint $table) {
            $table->dropColumn('question_web_search');
        });
    }
};
