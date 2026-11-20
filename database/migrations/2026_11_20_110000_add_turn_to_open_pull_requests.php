<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whose turn an open pull request is: its reviewers and what each of them did, what
 * happened to it so far, and the AI's note on what the one at turn has to do. And
 * the accounts a user has on the hosts, to know which pull requests are theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_pull_requests', function (Blueprint $table) {
            $table->string('destination_branch')->nullable()->after('branch');
            $table->timestamp('opened_at')->nullable()->after('awaiting_approval');
            // When the current head was first seen: an approval from before it is of older code.
            $table->timestamp('head_since')->nullable()->after('opened_at');
            $table->json('reviewers')->nullable()->after('head_since');
            $table->json('history')->nullable()->after('reviewers');
            $table->json('note')->nullable()->after('history');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('source_accounts')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('source_accounts');
        });

        Schema::table('open_pull_requests', function (Blueprint $table) {
            $table->dropColumn(['destination_branch', 'opened_at', 'head_since', 'reviewers', 'history', 'note']);
        });
    }
};
