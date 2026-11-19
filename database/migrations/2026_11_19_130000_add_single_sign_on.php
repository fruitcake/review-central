<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who a user is at a provider they sign in with, such as Google: its own
        // id for them, which stays when their address there changes.
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('provider_id');
            $table->timestamps();
            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            // The organisations' domains whose people may join this tenant by
            // signing in, without an invitation: ["example.com"].
            $table->json('sign_in_domains')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('sign_in_domains');
        });

        Schema::dropIfExists('user_identities');
    }
};
