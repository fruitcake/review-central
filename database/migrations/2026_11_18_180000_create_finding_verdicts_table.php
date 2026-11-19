<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What someone thought of one finding of one run: right, not a problem, or wrong.
        Schema::create('finding_verdicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('finding_id', 64);
            $table->string('verdict', 20);
            $table->timestamps();
            $table->unique(['review_run_id', 'user_id', 'finding_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_verdicts');
    }
};
