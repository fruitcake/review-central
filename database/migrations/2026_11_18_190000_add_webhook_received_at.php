<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            // When the host last delivered a webhook that carried the right signature.
            $table->timestamp('webhook_received_at')->nullable()->after('webhook_secret');
        });

        Schema::table('repositories', function (Blueprint $table) {
            // The same for a delivery about this repository: a webhook on another
            // repository of the connection says nothing about this one.
            $table->timestamp('webhook_received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('webhook_received_at');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('webhook_received_at');
        });
    }
};
