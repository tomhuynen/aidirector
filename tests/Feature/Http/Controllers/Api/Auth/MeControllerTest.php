<?php

declare(strict_types=1);

use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\{getJson};

it('can get the current user', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    getJson(route('api.auth.me.view'))
        ->assertOk()
        ->assertJson(json_decode(UserResource::make($user)->toJson(), true));
});
