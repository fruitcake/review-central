<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What someone may do in a tenant: an admin manages its connections, keys, apps,
 * settings and members; a member reads, rates and starts reviews. Everyone in
 * one today becomes a member, and an administrator of the platform says who is
 * a tenant's admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->string('role', 16)->default('member')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
