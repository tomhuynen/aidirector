<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            // The short line, the spot and per person of the cast how they are staged; the description is built from it.
            $table->json('staging')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            $table->dropColumn('staging');
        });
    }
};
