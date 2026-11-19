<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Who may start a review by commenting /review on a pull request:
            // anyone who can comment, only the accounts listed, or nobody.
            $table->string('review_command', 16)->default('anyone');
            // The source control accounts that may, by nickname or account ID.
            $table->json('review_command_accounts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['review_command', 'review_command_accounts']);
        });
    }
};
