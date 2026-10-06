<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->unsignedSmallInteger('position');
            $table->string('title');
            $table->text('subject')->nullable();
            $table->text('action')->nullable();
            $table->text('takeaway');
            $table->text('notes')->nullable();
            $table->json('preferred_elements')->nullable();
            $table->string('status', 32);
            $table->string('purpose_override', 32)->nullable();
            $table->string('aspect_ratio_override', 8)->nullable();
            $table->unsignedSmallInteger('duration')->nullable();
            $table->unsignedBigInteger('chosen_option_id')->nullable();
            $table->json('storyline_options')->nullable();
            $table->json('chosen_storyline')->nullable();
            $table->json('storyline')->nullable();
            $table->text('storyline_error')->nullable();
            $table->json('keyframe_review')->nullable();
            $table->boolean('reviewing')->default(false);
            $table->json('rules')->nullable();
            $table->text('voice_over')->nullable();
            $table->json('voice_over_tracks')->nullable();
            $table->text('video_prompt')->nullable();
            $table->string('video_job_id', 128)->nullable();
            $table->timestamp('video_submitted_at')->nullable();
            $table->text('video_error')->nullable();
            $table->unsignedBigInteger('merged_into_id')->nullable()->index();
            $table->string('merge_transition', 16)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shots');
    }
};
