<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\User;

use function Pest\Laravel\{assertDatabaseHas, getJson, postJson};

it('can create a token', function () {
    $user = User::factory()->create();

    $response = postJson(route('api.auth.token.create'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'test-device',
    ])
        ->assertOk();

    $apiToken = $response->content();
    $token = PersonalAccessToken::findToken($apiToken);

    assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $token->tokenable_id,
        'tokenable_type' => $token->tokenable_type,
        'name' => $token->name,
        'token' => $token->token,
    ], 'landlord');
});

it('can access protected routes', function () {
    $user = User::factory()->create();

    getJson(route('api.auth.me.view'))
        ->assertUnauthorized();

    $token = $user->createToken('test-device')->plainTextToken;

    getJson(route('api.auth.me.view'), [
        'Authorization' => 'Bearer ' . $token,
    ])
        ->assertOk();
});
