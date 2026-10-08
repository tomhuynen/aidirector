<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shot's plan is agreed in a conversation with the plan director instead of
 * through rules: the conversation is kept, the rules are dropped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->json('plan_chat')->nullable()->after('storyline');
        });

        if (Schema::hasColumn('shots', 'rules')) {
            Schema::table('shots', function (Blueprint $table) {
                $table->dropColumn('rules');
            });
        }
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->dropColumn('plan_chat');
            $table->json('rules')->nullable();
        });
    }
};
