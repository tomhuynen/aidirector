<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('keyframes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shot_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title');
            $table->text('description');
            $table->text('prompt')->nullable();
            $table->unsignedBigInteger('render_id')->nullable();
            $table->boolean('rendering')->default(false);
            $table->text('render_error')->nullable();
            $table->timestamps();

            $table->index(['shot_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyframes');
    }
};
