<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_sources', function (Blueprint $table) {
            // Off for every source there is: their tickets were read only by the review until now.
            $table->boolean('readable_over_mcp')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_sources', function (Blueprint $table) {
            $table->dropColumn('readable_over_mcp');
        });
    }
};
