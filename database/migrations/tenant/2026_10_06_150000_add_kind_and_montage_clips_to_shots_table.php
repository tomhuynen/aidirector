<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            // A scene at one place, or a montage of separate stills; null until the planner chose.
            $table->string('kind', 16)->nullable()->after('status');
            // The clip per still of a montage while they render: position, job id, status and error.
            $table->json('montage_clips')->nullable()->after('video_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->dropColumn(['kind', 'montage_clips']);
        });
    }
};
