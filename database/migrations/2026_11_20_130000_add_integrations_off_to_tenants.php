<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The installation's apps a tenant may not sign in through. Kept as what is off,
 * so an app set up later is there for every tenant until it is turned off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('integrations_off')->nullable()->after('sign_in_domains');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('integrations_off');
        });
    }
};
