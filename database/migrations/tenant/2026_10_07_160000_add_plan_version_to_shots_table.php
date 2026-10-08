<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counts how often a shot's plan was reopened, so jobs queued for an older
 * plan can tell their work is no longer wanted.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->unsignedInteger('plan_version')->default(0)->after('plan_chat');
        });
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->dropColumn('plan_version');
        });
    }
};
