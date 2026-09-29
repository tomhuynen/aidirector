<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login attempts of tenant-scoped accounts (directors). Mirrors the landlord
 * logins table used for operator accounts.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('logins', function (Blueprint $table) {
            $table->id();
            $table->string('guard', 50)->index();
            $table->boolean('success')->index();
            $table->morphs('authenticatable');
            $table->string('user_agent');
            $table->string('ip', 50);
            $table->text('details');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logins');
    }
};
