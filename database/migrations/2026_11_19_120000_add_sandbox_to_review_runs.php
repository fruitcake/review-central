<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            // Where the reviewer ran when that was not Docker on the worker, how
            // long that sandbox ran and what it cost in dollars, as estimated from
            // its size and the rates in the configuration at the time.
            $table->string('sandbox', 20)->nullable()->after('memory_limit');
            $table->string('sandbox_size', 20)->nullable()->after('sandbox');
            $table->unsignedInteger('sandbox_seconds')->nullable()->after('sandbox_size');
            $table->decimal('sandbox_cost', 10, 4)->nullable()->after('sandbox_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('review_runs', function (Blueprint $table) {
            $table->dropColumn(['sandbox', 'sandbox_size', 'sandbox_seconds', 'sandbox_cost']);
        });
    }
};
