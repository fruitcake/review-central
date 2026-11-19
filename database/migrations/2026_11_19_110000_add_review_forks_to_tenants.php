<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Whether a pull request from a fork is reviewed by itself, on a push
            // and on a comment: its code is anyone's, and runs in the sandbox with
            // the tenant's AI key and on its budget.
            $table->boolean('review_forks')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('review_forks');
        });
    }
};
