<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function create(array $input): User
    {
        Validator::make($input, [
            'invite_code' => $this->inviteCodeRules(),
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'tenant_id' => $input['tenant_id'] ?? Tenant::current()?->id,
        ]);

        return $user;
    }

    /**
     * Registration is gated by an invite code while the app is in testing.
     *
     * @return array<int, mixed>
     */
    private function inviteCodeRules(): array
    {
        $code = Config::get('app.invite_code');

        if (blank($code)) {
            return ['nullable', 'string'];
        }

        return [
            'required',
            'string',
            function (string $attribute, mixed $value, Closure $fail) use ($code): void {
                if (! hash_equals((string) $code, (string) $value)) {
                    $fail(__('The invite code is not valid.'));
                }
            },
        ];
    }
}
