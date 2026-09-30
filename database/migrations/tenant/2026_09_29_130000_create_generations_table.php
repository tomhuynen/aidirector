<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every AI call made on behalf of a director, with its usage and cost.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('director_id')->constrained()->cascadeOnDelete();
            $table->morphs('generatable');
            $table->string('kind', 16);
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->text('prompt')->nullable();
            $table->decimal('cost', 10, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('usage')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
