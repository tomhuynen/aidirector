<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shot starts from the director's idea and is told by its storyline; the
 * takeaway it had to teach goes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->renameColumn('notes', 'idea');
            $table->dropColumn('takeaway');
        });
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->renameColumn('idea', 'notes');
            $table->text('takeaway')->nullable();
        });
    }
};
