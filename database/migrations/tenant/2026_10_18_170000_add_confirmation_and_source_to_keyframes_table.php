<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The director confirms the end frame of a shot before the shots that
 * continue from it are drawn: the confirmed render is kept, so a changed end
 * frame drops the confirmation. The start frame of a shot that continues
 * another keeps the render it was drawn from, so a follower drawn from an
 * earlier end frame can be told apart.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            $table->unsignedBigInteger('confirmed_render_id')->nullable()->after('render_id');
            $table->unsignedBigInteger('source_render_id')->nullable()->after('confirmed_render_id');
        });
    }

    public function down(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            $table->dropColumn(['confirmed_render_id', 'source_render_id']);
        });
    }
};
