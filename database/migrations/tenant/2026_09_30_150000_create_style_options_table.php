<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('style_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('style_options');
            $table->unsignedSmallInteger('round');
            $table->unsignedSmallInteger('position');
            $table->json('style');
            $table->text('prompt');
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_options');
    }
};
