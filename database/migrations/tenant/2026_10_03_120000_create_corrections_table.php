<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->unsignedBigInteger('shot_id')->nullable();
            $table->unsignedBigInteger('keyframe_id')->nullable();
            $table->string('source', 16);
            $table->text('request');
            $table->text('context')->nullable();
            $table->string('kind', 16)->nullable();
            $table->string('category', 64)->nullable();
            $table->text('rule')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'category']);
        });

        Schema::create('project_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->string('category', 64);
            $table->text('text');
            $table->string('status', 16);
            $table->timestamps();

            $table->unique(['project_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_rules');
        Schema::dropIfExists('corrections');
    }
};
