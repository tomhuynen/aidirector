<?php

declare(strict_types=1);

use App\Models\Login;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->password = 'password123';

    // Create test user
    $this->user = User::create([
        'name' => 'test user',
        'email' => 'test@example.com',
        'password' => Hash::make($this->password),
    ]);
});

it('records successful login attempts in the database', function () {
    // Initial check - no logins should exist
    expect(Login::count())->toBe(0);

    // Fire a login event manually
    Event::dispatch(new LoginEvent(
        'web',
        $this->user,
        false // remember
    ));

    // A login record should have been created
    expect(Login::count())->toBe(1);

    // Verify the login record details
    $login = Login::first();
    expect($login->authenticatable_id)->toBe($this->user->id);
    expect($login->authenticatable_type)->toBe('user');
    expect($login->success)->toBeTrue();
    expect($login->guard)->toBe('web');
    expect($login->details)->toHaveKey('remember');
});

it('records failed login attempts in the database', function () {
    // Initial check - no logins should exist
    expect(Login::count())->toBe(0);

    // Fire a failed login event manually
    Event::dispatch(new Failed(
        'web',
        $this->user,
        ['email' => 'test@example.com', 'password' => 'wrong-password']
    ));

    // A login record should have been created
    expect(Login::count())->toBe(1);

    // Verify the login record details
    $login = Login::first();
    expect($login->authenticatable_id)->toBe($this->user->id);
    expect($login->authenticatable_type)->toBe('user');
    expect($login->success)->toBeFalse();
    expect($login->guard)->toBe('web');
    expect($login->details)->toHaveKey('credentials');
    expect($login->details['credentials'])->toHaveKey('email');
    expect($login->details['credentials']['email'])->toBe('test@example.com');
    // Password should be excluded from details
    expect($login->details['credentials'])->not->toHaveKey('password');
});

it('includes request metadata in login records', function () {
    // Initial check - no logins should exist
    expect(Login::count())->toBe(0);

    // Simulate login event
    Event::dispatch(new LoginEvent('web', $this->user, false));

    // A login record should have been created
    expect(Login::count())->toBe(1);

    // Verify the login record details
    $login = Login::first();
    expect($login->authenticatable_id)->toBe($this->user->id);
    expect($login->success)->toBeTrue();

    // IP and User-Agent are captured from the request in test environment
    expect($login->ip)->not->toBeNull();
    expect($login->user_agent)->not->toBeNull();
});

it('tracks multiple login attempts for the same user', function () {
    // Initial check - no logins should exist
    expect(Login::count())->toBe(0);

    // Simulate successful login
    Event::dispatch(new LoginEvent('web', $this->user, false));

    // Then simulate failed login
    Event::dispatch(new Failed('web', $this->user, ['email' => 'test@example.com', 'password' => 'wrong']));

    // Two login records should exist
    expect(Login::count())->toBe(2);

    // Get the logins in chronological order
    $logins = Login::orderBy('created_at')->get();

    // First login should be successful
    expect($logins[0]->success)->toBeTrue();

    // Second login should be a failure
    expect($logins[1]->success)->toBeFalse();
});
