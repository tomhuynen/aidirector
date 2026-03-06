<?php

declare(strict_types=1);

it('merges shared data properties into inertia page responses', function () {
    $response = $this->getJson('/docs/admin.json');

    $response->assertOk();

    $schema = $response->json();
    $paths = $schema['paths'] ?? [];

    // Find a GET route that returns an Inertia page (should have shared data merged)
    $foundSharedData = false;

    foreach ($paths as $path => $methods) {
        if (! isset($methods['get'])) {
            continue;
        }

        $responseSchema = data_get($methods, 'get.responses.200.content.application/json.schema');

        if (! $responseSchema || ! isset($responseSchema['properties'])) {
            continue;
        }

        $properties = array_keys($responseSchema['properties']);

        if (in_array('app', $properties) && in_array('isImpersonated', $properties) && in_array('page', $properties)) {
            $foundSharedData = true;

            // Verify 'app' has the expected nested properties
            $appProperties = $responseSchema['properties']['app']['properties'] ?? [];
            expect($appProperties)->toHaveKeys(['env', 'title', 'route', 'account', 'navigation']);

            break;
        }
    }

    expect($foundSharedData)->toBeTrue('No Inertia page response contained shared data properties (app, isImpersonated, page)');
});

it('types table resources as objects instead of strings', function () {
    $response = $this->getJson('/docs/admin.json');

    $response->assertOk();

    $schema = data_get(
        $response->json(),
        'paths./admin/accounts.get.responses.200.content.application/json.schema',
    );

    expect($schema['properties']['accounts'])->toBe([
        '$ref' => '#/components/schemas/TableResource',
    ]);
});
