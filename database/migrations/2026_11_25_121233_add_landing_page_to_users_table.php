<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an admin chose to land after signing in, and in which tenant: see
 * App\Enums\LandingPage. Null for where their role sends them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('landing_page', 16)->nullable()->after('source_accounts');
            $table->foreignId('landing_tenant_id')->nullable()->after('landing_page')->constrained('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landing_tenant_id');
            $table->dropColumn('landing_page');
        });
    }
};
