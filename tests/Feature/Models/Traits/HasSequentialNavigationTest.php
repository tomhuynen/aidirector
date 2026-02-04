<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\TestPost;

beforeEach(function () {
    // Create test table
    TestPost::setup();

    // Create test data
    $this->posts = collect([
        TestPost::create([
            'title' => 'First Post',
            'content' => 'Content 1',
            'sort_order' => 10,
            'published_at' => now()->subDays(5),
            'is_published' => true,
        ]),
        TestPost::create([
            'title' => 'Second Post',
            'content' => 'Content 2',
            'sort_order' => 20,
            'published_at' => now()->subDays(3),
            'is_published' => true,
        ]),
        TestPost::create([
            'title' => 'Third Post',
            'content' => 'Content 3',
            'sort_order' => 30,
            'published_at' => now()->subDays(1),
            'is_published' => false,
        ]),
        TestPost::create([
            'title' => 'Fourth Post',
            'content' => 'Content 4',
            'sort_order' => 40,
            'published_at' => now(),
            'is_published' => true,
        ]),
    ]);
});

afterEach(function () {
    TestPost::cleanup();
});

describe('basic navigation by id', function () {
    it('can get the previous post by id', function () {
        $post = $this->posts[2]; // Third post (id: 3)
        $previous = $post->previous();

        expect($previous)->not->toBeNull()
            ->and($previous->id)->toBe($this->posts[1]->id)
            ->and($previous->title)->toBe('Second Post');
    });

    it('can get the next post by id', function () {
        $post = $this->posts[1]; // Second post (id: 2)
        $next = $post->next();

        expect($next)->not->toBeNull()
            ->and($next->id)->toBe($this->posts[2]->id)
            ->and($next->title)->toBe('Third Post');
    });

    it('returns null for previous when at the beginning', function () {
        $firstPost = $this->posts[0];
        $previous = $firstPost->previous();

        expect($previous)->toBeNull();
    });

    it('returns null for next when at the end', function () {
        $lastPost = $this->posts[3];
        $next = $lastPost->next();

        expect($next)->toBeNull();
    });
});

describe('navigation by custom column', function () {
    it('can navigate by sort_order column', function () {
        $post = $this->posts[1]; // sort_order: 20
        $previous = $post->previous('sort_order');
        $next = $post->next('sort_order');

        expect($previous)->not->toBeNull()
            ->and($previous->sort_order)->toBe(10)
            ->and($next)->not->toBeNull()
            ->and($next->sort_order)->toBe(30);
    });

    it('can navigate by published_at column', function () {
        $post = $this->posts[1]; // published_at: 3 days ago
        $previous = $post->previous('published_at');
        $next = $post->next('published_at');

        expect($previous)->not->toBeNull()
            ->and($previous->title)->toBe('First Post')
            ->and($next)->not->toBeNull()
            ->and($next->title)->toBe('Third Post');
    });
});

describe('direction handling', function () {
    it('handles ascending direction correctly', function () {
        $post = $this->posts[1]; // sort_order: 20
        $previous = $post->previous('sort_order', 'asc');
        $next = $post->next('sort_order', 'asc');

        expect($previous->sort_order)->toBe(10)
            ->and($next->sort_order)->toBe(30);
    });

    it('handles descending direction correctly', function () {
        $post = $this->posts[1]; // sort_order: 20
        $previous = $post->previous('sort_order', 'desc');
        $next = $post->next('sort_order', 'desc');

        expect($previous->sort_order)->toBe(30)
            ->and($next->sort_order)->toBe(10);
    });
});

describe('siblings method', function () {
    it('returns both previous and next siblings', function () {
        $post = $this->posts[1]; // Middle post
        $siblings = $post->siblings();

        expect($siblings)->toHaveKey('previous')
            ->and($siblings)->toHaveKey('next')
            ->and($siblings['previous'])->not->toBeNull()
            ->and($siblings['next'])->not->toBeNull()
            ->and($siblings['previous']->id)->toBe($this->posts[0]->id)
            ->and($siblings['next']->id)->toBe($this->posts[2]->id);
    });

    it('handles edge cases in siblings', function () {
        $firstPost = $this->posts[0];
        $lastPost = $this->posts[3];

        $firstSiblings = $firstPost->siblings();
        $lastSiblings = $lastPost->siblings();

        expect($firstSiblings['previous'])->toBeNull()
            ->and($firstSiblings['next'])->not->toBeNull()
            ->and($lastSiblings['previous'])->not->toBeNull()
            ->and($lastSiblings['next'])->toBeNull();
    });

    it('works with custom column and direction in siblings', function () {
        $post = $this->posts[1];
        $siblings = $post->siblings('sort_order', 'desc');

        expect($siblings['previous']->sort_order)->toBe(30)
            ->and($siblings['next']->sort_order)->toBe(10);
    });
});

describe('query scopes', function () {
    it('can use previous scope with additional constraints', function () {
        $post = $this->posts[2]; // Third post

        $previousPublished = TestPost::previousBy($post->id)
            ->where('is_published', true)
            ->first();

        expect($previousPublished)->not->toBeNull()
            ->and($previousPublished->id)->toBe($this->posts[1]->id)
            ->and($previousPublished->is_published)->toBeTrue();
    });

    it('can use next scope with additional constraints', function () {
        $post = $this->posts[1]; // Second post

        $nextPublished = TestPost::nextBy($post->id)
            ->where('is_published', true)
            ->first();

        expect($nextPublished)->not->toBeNull()
            ->and($nextPublished->id)->toBe($this->posts[3]->id)
            ->and($nextPublished->is_published)->toBeTrue();
    });

    it('respects custom column in scopes', function () {
        $post = $this->posts[1]; // sort_order: 20

        $previous = TestPost::previousBy($post->sort_order, 'sort_order')->first();
        $next = TestPost::nextBy($post->sort_order, 'sort_order')->first();

        expect($previous->sort_order)->toBe(10)
            ->and($next->sort_order)->toBe(30);
    });

    it('respects direction in scopes', function () {
        $post = $this->posts[1]; // sort_order: 20

        $previous = TestPost::previousBy($post->sort_order, 'sort_order', 'desc')->first();
        $next = TestPost::nextBy($post->sort_order, 'sort_order', 'desc')->first();

        expect($previous->sort_order)->toBe(30)
            ->and($next->sort_order)->toBe(10);
    });
});

describe('edge cases', function () {
    it('handles null column values gracefully', function () {
        $postWithNull = TestPost::create([
            'title' => 'Null Sort Order',
            'content' => 'Content',
            'sort_order' => null,
            'published_at' => now(),
            'is_published' => true,
        ]);

        $previous = $postWithNull->previous('sort_order');
        $next = $postWithNull->next('sort_order');

        expect($previous)->toBeNull()
            ->and($next)->toBeNull();
    });

    it('works with single record', function () {
        TestPost::query()->delete();

        $singlePost = TestPost::create([
            'title' => 'Only Post',
            'content' => 'Lonely content',
            'sort_order' => 100,
            'published_at' => now(),
            'is_published' => true,
        ]);

        expect($singlePost->previous())->toBeNull()
            ->and($singlePost->next())->toBeNull();

        $siblings = $singlePost->siblings();
        expect($siblings['previous'])->toBeNull()
            ->and($siblings['next'])->toBeNull();
    });

    it('works with duplicate values', function () {
        // Create posts with same sort_order
        $duplicate1 = TestPost::create([
            'title' => 'Duplicate 1',
            'content' => 'Content',
            'sort_order' => 50,
            'published_at' => now()->subHour(),
            'is_published' => true,
        ]);

        $duplicate2 = TestPost::create([
            'title' => 'Duplicate 2',
            'content' => 'Content',
            'sort_order' => 50,
            'published_at' => now(),
            'is_published' => true,
        ]);

        // Should still work, returning the first match
        $previous = $duplicate2->previous('sort_order');
        expect($previous)->not->toBeNull();
    });
});

describe('performance and efficiency', function () {
    it('executes minimal queries', function () {
        DB::enableQueryLog();

        $post = $this->posts[1];
        $post->previous();
        $post->next();

        $queries = DB::getQueryLog();

        // Should be exactly 2 queries (one for previous, one for next)
        expect(count($queries))->toBe(2);

        DB::disableQueryLog();
    });

    it('siblings method is efficient', function () {
        DB::enableQueryLog();

        $post = $this->posts[1];
        $post->siblings();

        $queries = DB::getQueryLog();

        // Should be exactly 2 queries (one for previous, one for next)
        expect(count($queries))->toBe(2);

        DB::disableQueryLog();
    });
});
