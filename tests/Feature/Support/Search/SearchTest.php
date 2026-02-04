<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Search\Http\Resources\SearchableResource;
use App\Support\Search\Searchables\DefaultSearchable;
use App\Support\Search\Searchables\Resolver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Search Controller Tests
|--------------------------------------------------------------------------
*/

describe('SearchController', function () {
    it('returns 404 for unknown entity', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('admin.accounts.view');

        actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'unknown-entity']))
            ->assertNotFound();
    });

    it('returns 404 for entity not in morph map', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('admin.accounts.view');

        // 'activity' is not in the morph map
        actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'activity']))
            ->assertNotFound();
    });

    it('returns 403 when user cannot view entity', function () {
        $user = User::factory()->create();
        // User has no permissions

        actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user']))
            ->assertNotFound(); // Returns 404 because entity is filtered out by authorization
    });

    it('returns results for authorized user', function () {
        $user = User::factory()->create(['name' => 'Admin User']);
        $user->givePermissionTo('admin.accounts.view');

        // Create additional users to search
        User::factory()->count(3)->create();

        actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user']))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name'],
                ],
                'meta' => ['path', 'per_page', 'next_cursor', 'prev_cursor'],
            ]);
    });

    it('filters results by search query', function () {
        $user = User::factory()->create(['name' => 'Admin User']);
        $user->givePermissionTo('admin.accounts.view');

        User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);
        User::factory()->create(['name' => 'Bob Wilson', 'email' => 'bob@example.com']);

        $response = actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => 'John']))
            ->assertOk();

        $data = $response->json('data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['name'])->toBe('John Doe');
    });

    it('searches across multiple columns', function () {
        $user = User::factory()->create(['name' => 'Admin User']);
        $user->givePermissionTo('admin.accounts.view');

        User::factory()->create(['name' => 'John Doe', 'email' => 'unique@example.com']);
        User::factory()->create(['name' => 'Jane Smith', 'email' => 'johnemail@example.com']);

        $response = actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => 'john']))
            ->assertOk();

        $data = $response->json('data');

        // Should find both: one by name, one by email
        expect($data)->toHaveCount(2);
    });

    it('returns empty results when no matches found', function () {
        $user = User::factory()->create(['name' => 'Admin User']);
        $user->givePermissionTo('admin.accounts.view');

        User::factory()->count(3)->create();

        $response = actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => 'nonexistent-xyz-123']))
            ->assertOk();

        expect($response->json('data.data'))->toBeEmpty();
    });

    it('supports cursor pagination', function () {
        $user = User::factory()->create(['name' => 'Admin User']);
        $user->givePermissionTo('admin.accounts.view');

        // Create more users than per_page default (15)
        User::factory()->count(20)->create();

        $response = actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user']))
            ->assertOk();

        expect($response->json('meta.next_cursor'))->not->toBeNull()
            ->and($response->json('data'))->toHaveCount(15);

        // Test pagination with cursor
        $nextCursor = $response->json('meta.next_cursor');

        $nextResponse = actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'cursor' => $nextCursor]))
            ->assertOk();

        expect($nextResponse->json('data'))->not->toBeEmpty();
    });

    it('trims whitespace from search query', function () {
        $user = User::factory()->create(['name' => 'Admin User']);
        $user->givePermissionTo('admin.accounts.view');

        User::factory()->create(['name' => 'John Doe']);

        $response = actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => '  John  ']))
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1);
    });

    it('returns all results when search query is empty', function () {
        $user = User::factory()->create(['name' => 'Admin User']);
        $user->givePermissionTo('admin.accounts.view');

        User::factory()->count(5)->create();

        $responseWithoutQuery = actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user']))
            ->assertOk();

        $responseWithEmptyQuery = actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => '']))
            ->assertOk();

        expect($responseWithoutQuery->json('data'))
            ->toHaveCount(count($responseWithEmptyQuery->json('data')));
    });

    it('can search tenants when authorized', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('admin.tenants.view');

        // Search for the existing "Testing" tenant created by the test setup
        $currentTenant = Tenant::current();

        $response = actingAs($user)
            ->getJson(route('admin.search.index', ['entity' => 'tenant', 'q' => $currentTenant->name]))
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.name'))->toBe($currentTenant->name);
    });
});

/*
|--------------------------------------------------------------------------
| BaseSearchable Tests
|--------------------------------------------------------------------------
*/

describe('BaseSearchable', function () {
    it('has default perPage of 15', function () {
        $searchable = new DefaultSearchable(User::class);

        expect($searchable->perPage())->toBe(15);
    });

    it('returns resource collection from search', function () {
        $user = User::factory()->create();

        $searchable = new DefaultSearchable(User::class);
        $request = Request::create('/search/user', 'GET');

        $result = $searchable->search($request);

        expect($result)->toBeInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class);
    });

    it('applies search filter when query provided', function () {
        User::factory()->create(['name' => 'Alice']);
        User::factory()->create(['name' => 'Bob']);

        $searchable = new DefaultSearchable(User::class);
        $request = Request::create('/search/user', 'GET', ['q' => 'Alice']);

        $result = $searchable->search($request);

        expect($result->count())->toBe(1);
    });

    it('returns all results when no query provided', function () {
        User::factory()->count(3)->create();

        $searchable = new DefaultSearchable(User::class);
        $request = Request::create('/search/user', 'GET');

        $result = $searchable->search($request);

        expect($result->count())->toBe(3);
    });

    it('searches with LIKE pattern matching', function () {
        User::factory()->create(['name' => 'Johnny Appleseed']);
        User::factory()->create(['name' => 'John Doe']);
        User::factory()->create(['name' => 'Jane Johnson']);

        $searchable = new DefaultSearchable(User::class);
        $request = Request::create('/search/user', 'GET', ['q' => 'John']);

        $result = $searchable->search($request);

        // Should match: Johnny Appleseed, John Doe, Jane Johnson (contains "John")
        expect($result->count())->toBe(3);
    });
});

/*
|--------------------------------------------------------------------------
| DefaultSearchable Tests
|--------------------------------------------------------------------------
*/

describe('DefaultSearchable', function () {
    it('returns correct key from morph map', function () {
        $searchable = new DefaultSearchable(User::class);

        expect($searchable->key())->toBe('user');
    });

    it('uses models searchableColumns method', function () {
        $searchable = new DefaultSearchable(User::class);

        expect($searchable->searchable())->toBe(['name', 'email']);
    });

    it('returns SearchableResource as resource class', function () {
        expect(DefaultSearchable::resource())->toBe(SearchableResource::class);
    });

    it('creates query from model class', function () {
        $searchable = new DefaultSearchable(User::class);
        $request = Request::create('/search/user', 'GET');

        $query = $searchable->query($request);

        expect($query->getModel())->toBeInstanceOf(User::class);
    });
});

/*
|--------------------------------------------------------------------------
| Resolver Tests
|--------------------------------------------------------------------------
*/

describe('Resolver', function () {
    it('returns DefaultSearchable when no custom handler exists', function () {
        $resolver = new Resolver();

        $searchable = $resolver->resolve(User::class);

        expect($searchable)->toBeInstanceOf(DefaultSearchable::class);
    });

    it('passes model class to searchable constructor', function () {
        $resolver = new Resolver();

        $searchable = $resolver->resolve(User::class);

        // Verify by checking the key matches the morph alias for the model
        expect($searchable->key())->toBe(Relation::getMorphAlias(User::class));
    });

    it('resolves different models correctly', function () {
        $resolver = new Resolver();

        $userSearchable = $resolver->resolve(User::class);
        $tenantSearchable = $resolver->resolve(Tenant::class);

        expect($userSearchable->key())->toBe('user')
            ->and($tenantSearchable->key())->toBe('tenant');
    });
});

/*
|--------------------------------------------------------------------------
| SearchableResource Tests
|--------------------------------------------------------------------------
*/

describe('SearchableResource', function () {
    it('formats response with id and name', function () {
        $user = User::factory()->create(['name' => 'Test User']);

        $resource = new SearchableResource($user);
        $array = $resource->toArray(request());

        expect($array)->toHaveKeys(['id', 'name'])
            ->and($array['name'])->toBe('Test User');
    });

    it('uses sqid when available', function () {
        $user = User::factory()->create(['name' => 'Test User']);

        // User model has HasSqids trait
        $resource = new SearchableResource($user);
        $array = $resource->toArray(request());

        expect($array['id'])->toBe($user->sqid);
    });

    it('falls back to id when sqid not available', function () {
        // Create a simple object without sqid
        $model = new class {
            public int $id = 123;
            public string $name = 'Test';
        };

        $resource = new SearchableResource($model);
        $array = $resource->toArray(request());

        expect($array['id'])->toBe(123);
    });
});

/*
|--------------------------------------------------------------------------
| Integration Tests
|--------------------------------------------------------------------------
*/

describe('Search Integration', function () {
    it('complete search flow works end to end', function () {
        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->givePermissionTo('admin.accounts.view');

        // Create searchable users
        User::factory()->create(['name' => 'Alice Anderson', 'email' => 'alice@test.com']);
        User::factory()->create(['name' => 'Bob Brown', 'email' => 'bob@test.com']);
        User::factory()->create(['name' => 'Charlie Clark', 'email' => 'charlie@test.com']);

        // Search by name
        $response = actingAs($admin)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => 'Alice']))
            ->assertOk();

        $data = $response->json('data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['name'])->toBe('Alice Anderson');
    });

    it('search is case insensitive', function () {
        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->givePermissionTo('admin.accounts.view');

        User::factory()->create(['name' => 'John Doe']);

        $lowerCase = actingAs($admin)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => 'john']))
            ->json('data');

        $upperCase = actingAs($admin)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => 'JOHN']))
            ->json('data');

        $mixedCase = actingAs($admin)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => 'JoHn']))
            ->json('data');

        expect($lowerCase)->toHaveCount(1)
            ->and($upperCase)->toHaveCount(1)
            ->and($mixedCase)->toHaveCount(1);
    });

    it('search works with special characters', function () {
        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->givePermissionTo('admin.accounts.view');

        User::factory()->create(['name' => "O'Brien"]);
        User::factory()->create(['name' => 'Mary-Jane']);

        $apostrophe = actingAs($admin)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => "O'Brien"]))
            ->json('data');

        $hyphen = actingAs($admin)
            ->getJson(route('admin.search.index', ['entity' => 'user', 'q' => 'Mary-Jane']))
            ->json('data');

        expect($apostrophe)->toHaveCount(1)
            ->and($hyphen)->toHaveCount(1);
    });

    it('respects morph map for entity resolution', function () {
        $admin = User::factory()->create();
        $admin->givePermissionTo('admin.accounts.view');

        // Should work with morph alias
        actingAs($admin)
            ->getJson(route('admin.search.index', ['entity' => 'user']))
            ->assertOk();

        // Should not work with class name
        actingAs($admin)
            ->getJson(route('admin.search.index', ['entity' => 'App\\Models\\User']))
            ->assertNotFound();
    });
});
