<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                // Primary sorting index
                $table->index(['created_at', 'id'], 'idx_activity_created_at_id');

                // For causer-specific queries (when filtering by user)
                $table->index(['causer_id', 'created_at', 'id'], 'idx_activity_causer_created_at');

                // For subject type filtering + sorting
                $table->index(['subject_type', 'created_at', 'id'], 'idx_activity_subject_created_at');
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->dropIndex('idx_activity_created_at_id');
                $table->dropIndex('idx_activity_causer_created_at');
                $table->dropIndex('idx_activity_subject_created_at');
            });
    }
};
