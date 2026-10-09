<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the director decided about each finding of the checks: fixed or left
 * as it is, so it can be measured per reviewer how often it is right.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviewer_verdicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shot_id')->index();
            $table->unsignedTinyInteger('keyframe')->nullable();
            $table->string('reviewer', 20);
            $table->text('note');
            $table->string('verdict', 20);
            $table->string('via', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviewer_verdicts');
    }
};
