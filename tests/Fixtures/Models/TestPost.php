<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\Traits\HasSequentialNavigation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class TestPost extends Model
{
    use HasSequentialNavigation;
    use UsesLandlordConnection;

    protected $table = 'test_posts';
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_published' => 'boolean',
        ];
    }

    public static function setup(): void
    {
        Schema::connection('landlord')->create('test_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->integer('sort_order')->nullable();
            $table->datetime('published_at')->nullable();
            $table->boolean('is_published')->default(false);
        });
    }

    public static function cleanup(): void
    {
        Schema::connection('landlord')->dropIfExists('test_posts');
    }
}
