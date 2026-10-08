<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Directors live in the tenant databases and their ids repeat across tenants: a director's token only works for its own tenant.
            $table->unsignedBigInteger('tenant_id')->nullable()->after('tokenable_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
