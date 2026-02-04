<?php

declare(strict_types=1);

namespace App\Tables\Admin\Users;

use App\Models\Login;
use App\Models\User;
use App\Support\Login\IpInfo;
use App\Support\Login\UserAgent;
use Illuminate\Contracts\Database\Eloquent\Builder;
use InertiaUI\Table\Columns\ColumnAlignment;
use InertiaUI\Table\Columns\TextColumn;
use InertiaUI\Table\Table;

class Logins extends Table
{
    protected bool $pagination = false;

    protected ?array $perPageOptions = [10];

    public function __construct(
        public User $user,
    ) {}

    public function resource(): Builder
    {
        return Login::query()
            ->with('authenticatable')
            ->whereMorphedTo('authenticatable', $this->user)
            ->where('success', true)
            ->orderBy('created_at', 'desc')
            ->limit(10);
    }

    public function columns(): array
    {
        return [
            TextColumn::make(
                attribute: 'created_at',
                header: __('Login At'),
                toggleable: false,
            ),
            TextColumn::make(
                attribute: 'ip',
                header: __('IP'),
                toggleable: false,
            )->mapAs(fn(string $ip) => IpInfo::getDetails($ip)),
            TextColumn::make(
                attribute: 'user_agent',
                header: __('User Agent'),
                alignment: ColumnAlignment::Right,
                toggleable: false,
            )->mapAs(fn(string $userAgent) => UserAgent::getDetails($userAgent)),
        ];
    }
}
