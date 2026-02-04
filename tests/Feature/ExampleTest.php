<?php

declare(strict_types=1);

it('returns a successful response', function () {
    $response = $this->get('/');
    $response->assertStatus(200);
});

it('has configuration loaded', function () {
    // Test configuration values from the test environment
    // We're not testing specific values since the env may change
    expect(config('database.connections.landlord.database'))->not->toBeNull();
    expect(config('app.name'))->not->toBeNull();
});
