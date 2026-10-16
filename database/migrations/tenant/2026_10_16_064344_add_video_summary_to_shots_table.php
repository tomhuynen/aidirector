<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            // What the video shows in a sentence or two, for the director, next to the prompt the video model got.
            $table->text('video_summary')->nullable()->after('video_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->dropColumn('video_summary');
        });
    }
};
