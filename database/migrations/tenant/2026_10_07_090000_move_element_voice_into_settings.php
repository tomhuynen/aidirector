<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Details that belong to one type of element, such as the voice of a person,
 * live in one settings column instead of a column each.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('elements', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('description');
        });

        if (Schema::hasColumn('elements', 'voice')) {
            DB::table('elements')->whereNotNull('voice')->orderBy('id')->each(function (object $element) {
                DB::table('elements')->where('id', $element->id)->update(['settings' => json_encode(['voice' => $element->voice])]);
            });

            Schema::table('elements', function (Blueprint $table) {
                $table->dropColumn('voice');
            });
        }
    }

    public function down(): void
    {
        Schema::table('elements', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
