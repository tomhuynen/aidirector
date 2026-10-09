<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deleted shot is kept with its keyframes, images and conversation, so what
 * the system can learn from it stays; only deleting the project removes it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
