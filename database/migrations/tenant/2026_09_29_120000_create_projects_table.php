<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('director_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->string('title');
            $table->string('purpose', 32);
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->json('style');
            $table->string('aspect_ratio', 8);
            $table->unsignedSmallInteger('default_duration');
            $table->string('video_resolution', 8)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
