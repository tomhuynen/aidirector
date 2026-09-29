<?php

declare(strict_types=1);

use App\Models\Director;
use App\Models\User;
use App\Notifications\Public\ResetPassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

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

    it('redirects authenticated directors away from guest pages', function () {
        actingAs(Director::factory()->create(), 'director')
            ->get(route('public.auth.login'))
            ->assertRedirect(route('public.projects.index'));
    });

    it('does not treat an admin session as a director', function () {
        actingAs(User::factory()->create())
            ->get(route('public.projects.index'))
            ->assertRedirect(route('public.auth.login'));
    });
});

describe('registration', function () {
    $payload = fn(array $overrides = []) => [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        ...$overrides,
    ];

    it('creates a director and logs them in', function () use ($payload) {
        Config::set('app.invite_code', null);

        post(route('public.auth.register.store'), $payload())
            ->assertRedirect(route('public.projects.index'));

        $director = Director::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($director, 'director');
        expect(User::query()->where('email', 'ada@example.com')->exists())->toBeFalse();
    });

    it('requires a valid invite code when one is configured', function () use ($payload) {
        Config::set('app.invite_code', 'secret');

        post(route('public.auth.register.store'), $payload(['invite_code' => 'wrong']))
            ->assertSessionHasErrors('invite_code');

        expect(Director::query()->count())->toBe(0);
    });

    it('accepts the configured invite code', function () use ($payload) {
        Config::set('app.invite_code', 'secret');

        post(route('public.auth.register.store'), $payload(['invite_code' => 'secret']))
            ->assertRedirect(route('public.projects.index'));
    });

    it('rejects a duplicate email', function () use ($payload) {
        Config::set('app.invite_code', null);
        Director::factory()->create(['email' => 'ada@example.com']);

        post(route('public.auth.register.store'), $payload())->assertSessionHasErrors('email');
    });
});

describe('login and logout', function () {
    it('logs a director in with valid credentials', function () {
        $director = Director::factory()->create(['password' => 'correct-horse-battery-staple']);

        post(route('public.auth.login.store'), ['email' => $director->email, 'password' => 'correct-horse-battery-staple'])
            ->assertRedirect(route('public.projects.index'));

        $this->assertAuthenticatedAs($director, 'director');
    });

    it('rejects invalid credentials', function () {
        $director = Director::factory()->create();

        post(route('public.auth.login.store'), ['email' => $director->email, 'password' => 'nope'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('director');
    });

    it('logs a director out', function () {
        actingAs(Director::factory()->create(), 'director')
            ->post(route('public.auth.logout'))
            ->assertRedirect(route('public.auth.login'));

        $this->assertGuest('director');
    });

    it('sends guests on public pages to the public login', function () {
        get(route('public.projects.index'))->assertRedirect(route('public.auth.login'));
    });

    it('sends guests on admin pages to the admin login', function () {
        get('/admin')->assertRedirect(route('login'));
    });
});

describe('password reset', function () {
    it('emails a reset link pointing at the public frontend', function () {
        Notification::fake();
        $director = Director::factory()->create();

        post(route('public.auth.forgot-password.store'), ['email' => $director->email])->assertRedirect();

        Notification::assertSentTo($director, ResetPassword::class, function (ResetPassword $notification) use ($director) {
            $mail = $notification->toMail($director);

            return str_contains($mail->actionUrl, route('public.auth.reset-password', $notification->token, false));
        });
    });

    it('resets the password with a valid token', function () {
        $director = Director::factory()->create();
        $token = Password::broker('directors')->createToken($director);

        post(route('public.auth.reset-password.store'), [
            'token' => $token,
            'email' => $director->email,
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('public.auth.login'));

        expect(Hash::check('correct-horse-battery-staple', $director->fresh()->password))->toBeTrue();
    });

    it('rejects an invalid token', function () {
        $director = Director::factory()->create();

        post(route('public.auth.reset-password.store'), [
            'token' => 'invalid',
            'email' => $director->email,
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertSessionHasErrors('email');
    });
});
