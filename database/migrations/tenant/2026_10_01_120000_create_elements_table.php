<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cast and sets of a project: recurring people, places and objects with
 * a fixed description and a reference image, reused across shots.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->string('type', 16);
            $table->string('name');
            $table->text('description');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->boolean('rendering')->default(false);
            $table->text('render_error')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'type']);
        });

        Schema::create('element_keyframe', function (Blueprint $table) {
            $table->foreignId('element_id')->constrained();
            $table->foreignId('keyframe_id')->constrained();

            $table->primary(['element_id', 'keyframe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('element_keyframe');
        Schema::dropIfExists('elements');
    }
};
