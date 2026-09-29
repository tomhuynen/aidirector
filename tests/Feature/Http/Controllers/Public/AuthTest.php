<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Config;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

describe('public auth pages', function () {
    it('shows the login page to guests', function () {
        get(route('public.auth.login'))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('auth/login'));
    });

    it('shows the register page with the invite flag', function () {
        Config::set('app.invite_code', 'secret');

        get(route('public.auth.register'))
            ->assertSuccessful()
            ->assertInertia(fn($page) => $page->component('auth/register')->where('inviteRequired', true));
    });

    it('redirects authenticated users away from guest pages', function () {
        actingAs(User::factory()->create())
            ->get(route('public.auth.login'))
            ->assertRedirect(route('public.projects.index'));
    });
});

describe('registration', function () {
    it('creates a user on the current tenant', function () {
        Config::set('app.invite_code', null);

        post('/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('public.projects.index'));

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        expect($user->tenant_id)->toBe(Tenant::current()->id);
        $this->assertAuthenticatedAs($user);
    });

    it('requires a valid invite code when one is configured', function () {
        Config::set('app.invite_code', 'secret');

        post('/auth/register', [
            'invite_code' => 'wrong',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertSessionHasErrors('invite_code');

        expect(User::query()->where('email', 'ada@example.com')->exists())->toBeFalse();
    });

    it('accepts the configured invite code', function () {
        Config::set('app.invite_code', 'secret');

        post('/auth/register', [
            'invite_code' => 'secret',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('public.projects.index'));
    });
});

describe('login redirects', function () {
    it('sends public logins to the projects page', function () {
        $user = User::factory()->create(['password' => 'correct-horse-battery-staple']);

        $this->from(route('public.auth.login'))
            ->post('/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery-staple'])
            ->assertRedirect(route('public.projects.index'));
    });

    it('sends admin logins to the admin dashboard', function () {
        $user = User::factory()->create(['password' => 'correct-horse-battery-staple']);

        $this->from('/auth/login')
            ->post('/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery-staple'])
            ->assertRedirect(route('admin.dashboard.index'));
    });

    it('sends guests on public pages to the public login', function () {
        get(route('public.projects.index'))->assertRedirect(route('public.auth.login'));
    });

    it('sends guests on admin pages to the admin login', function () {
        get('/admin')->assertRedirect(route('login'));
    });

    it('sends public logouts to the public login', function () {
        actingAs(User::factory()->create())
            ->from(route('public.projects.index'))
            ->post('/auth/logout')
            ->assertRedirect(route('public.auth.login'));
    });
});
