<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('elements', function (Blueprint $table) {
            // The voice a person speaks with as a presenter, male or female, judged from their picture.
            $table->string('voice', 8)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('elements', function (Blueprint $table) {
            $table->dropColumn('voice');
        });
    }
};
