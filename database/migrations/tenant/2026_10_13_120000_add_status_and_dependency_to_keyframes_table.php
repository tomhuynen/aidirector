<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every keyframe one status and the keyframe it is drawn from, so
 * keyframes are drawn in order and redrawn when what they depend on changes.
 * The draw version tells a running draw whether it is still wanted; the
 * layout keeps where each of the cast stands, for the keyframe after it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            $table->string('status', 16)->default('planned')->after('render_id');
            $table->foreignId('depends_on_id')->nullable()->after('status')->constrained('keyframes')->nullOnDelete();
            $table->unsignedInteger('draw_version')->default(0)->after('depends_on_id');
            $table->json('layout')->nullable()->after('draw_version');
        });

        DB::table('keyframes')->whereNotNull('render_id')->update(['status' => 'ready']);

        Schema::table('keyframes', function (Blueprint $table) {
            $table->dropColumn(['rendering', 'render_stage']);
        });
    }

    public function down(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            $table->boolean('rendering')->default(false)->after('render_id');
            $table->string('render_stage', 16)->nullable()->after('rendering');
            $table->dropConstrainedForeignId('depends_on_id');
            $table->dropColumn(['status', 'draw_version', 'layout']);
        });
    }
};
