<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A team's own rules for its reviews, for one repository or for all of them.
        Schema::create('review_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Null is every repository of the tenant.
            $table->foreignId('repository_id')->nullable()->constrained()->cascadeOnDelete();
            // check: report a change that breaks it; convention: not a defect here.
            $table->string('kind', 16);
            $table->string('title');
            $table->text('body')->nullable();
            // Globs the rule is limited to; null for every file.
            $table->json('paths')->nullable();
            // The severity a broken check is reported at; null leaves it to the reviewer.
            $table->string('severity', 8)->nullable();
            $table->boolean('enabled')->default(true);
            // Where a rule made from a finding came from, as "<run uuid>:<finding id>".
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'repository_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_rules');
    }
};
