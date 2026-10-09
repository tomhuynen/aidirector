<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Common words an object finder knows for an object, such as "red wall
 * phone", written once from its description, so it can be found in a place.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('elements', function (Blueprint $table) {
            $table->json('detection_words')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('elements', function (Blueprint $table) {
            $table->dropColumn('detection_words');
        });
    }
};
