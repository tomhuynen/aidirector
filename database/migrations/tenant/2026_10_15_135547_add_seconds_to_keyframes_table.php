<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            // How long the keyframe lasts in the shot, set by the director; empty shares the shot length evenly.
            $table->decimal('seconds', 4, 1)->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            $table->dropColumn('seconds');
        });
    }
};
