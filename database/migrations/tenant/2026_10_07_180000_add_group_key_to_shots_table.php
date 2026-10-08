<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shots planned together from one conversation share a group, shown together
 * in the shot list so they can be merged into one video later.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->string('group_key', 26)->nullable()->after('plan_version')->index();
        });
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->dropColumn('group_key');
        });
    }
};
