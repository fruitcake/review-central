<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's own account at a host, such as Bitbucket, that they connected to act
 * there as themselves: one per user and host, and gone with the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('host', 30);
            $table->string('account_id');
            $table->string('nickname')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_connections');
    }
};
