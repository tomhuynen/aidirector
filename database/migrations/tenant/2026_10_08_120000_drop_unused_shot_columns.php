<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shots are planned in the plan chat: the hand-written subject and action,
 * the cast picked in the brief and the storyline suggestions to choose from
 * are gone, and so is the note shown while a check's mistake was fixed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(
                ['subject', 'action', 'preferred_elements', 'chosen_option_id', 'storyline_options'],
                fn(string $column) => Schema::hasColumn('shots', $column),
            )));
        });

        if (Schema::hasColumn('keyframes', 'render_note')) {
            Schema::table('keyframes', function (Blueprint $table) {
                $table->dropColumn('render_note');
            });
        }
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->string('subject')->nullable();
            $table->string('action')->nullable();
            $table->json('preferred_elements')->nullable();
            $table->unsignedBigInteger('chosen_option_id')->nullable();
            $table->json('storyline_options')->nullable();
        });

        Schema::table('keyframes', function (Blueprint $table) {
            $table->text('render_note')->nullable();
        });
    }
};
