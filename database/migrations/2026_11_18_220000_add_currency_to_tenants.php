<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // What the tenant reads its costs in, and what its monthly budget is in.
            $table->string('currency', 3)->default('USD')->after('monthly_budget');
        });

        // Costs are stored in dollars, as the model prices are; this is what one
        // dollar is worth in each other currency a tenant can read them in.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->string('currency', 3)->primary();
            $table->decimal('rate', 12, 6);
            $table->timestamp('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
