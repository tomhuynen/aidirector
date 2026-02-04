<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Login;
use App\Models\TenantLogin;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TrackLoginAttempts
{
    public function __construct(
        private readonly Request $request
    ) {}

    public function handleLogin(LoginEvent $event)
    {
        /** @var Model&Authenticatable $user */
        $user = $event->user;

        $class = in_array(UsesTenantConnection::class, trait_uses_recursive($user))
            ? TenantLogin::class
            : Login::class;

        $class::create([
            'guard' => $event->guard,
            'success' => true,
            'authenticatable_type' => $user->getMorphClass(),
            'authenticatable_id' => $user->getKey(),
            'user_agent' => $this->getUserAgent(),
            'ip' => $this->getIp(),
            'details' => [
                'remember' => $event->remember,
            ],
        ]);
    }

    public function handleFailure(Failed $event)
    {
        /** @var Model&Authenticatable $user */
        $user = $event->user ?? new User();

        $credentials = Arr::except($event->credentials, [
            'password',
        ]);

        Login::create([
            'guard' => $event->guard,
            'success' => false,
            'authenticatable_type' => $user->getMorphClass(),
            'authenticatable_id' => $user->getKey() ?? 0,
            'user_agent' => $this->getUserAgent(),
            'ip' => $this->getIp(),
            'details' => [
                'credentials' => $credentials,
            ],
        ]);
    }

    private function getUserAgent()
    {
        $userAgent = $this->request->userAgent() ?? '<unknown>';
        $userAgent = Str::limit($userAgent, 250);

        return $userAgent;
    }

    private function getIp()
    {
        return $this->request->ip() ?? '';
    }
}
