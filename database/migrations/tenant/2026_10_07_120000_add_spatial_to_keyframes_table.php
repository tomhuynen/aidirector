<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            // The one fact a viewer must be able to check at a glance, such as where someone stands relative to a line.
            $table->text('spatial')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            $table->dropColumn('spatial');
        });
    }
};
