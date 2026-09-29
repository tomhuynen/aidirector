<?php

declare(strict_types=1);

namespace App\Http\Requests\Public\Auth;

use App\Models\Director;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'invite_code' => $this->inviteCodeRules(),
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(Director::class)],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ];
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
