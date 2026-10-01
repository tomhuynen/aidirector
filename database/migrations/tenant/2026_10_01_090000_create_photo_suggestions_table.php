<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('photo_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('batch');
            $table->unsignedSmallInteger('position');
            $table->string('query');
            $table->text('image_url');
            $table->text('thumbnail_url');
            $table->text('source_url')->nullable();
            $table->string('title')->nullable();
            $table->string('domain')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('from_website')->default(false);
            $table->timestamp('picked_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'batch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_suggestions');
    }
};
