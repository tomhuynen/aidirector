<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('element_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->string('type', 16);
            $table->text('brief')->nullable();
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'type']);
        });

        Schema::create('element_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('element_round_id')->constrained();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->text('description');
            $table->unsignedBigInteger('source_media_id')->nullable();
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('element_suggestions');
        Schema::dropIfExists('element_rounds');
    }
};
