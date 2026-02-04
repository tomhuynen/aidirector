<?php

declare(strict_types=1);

namespace App\Tables\Admin\Users;

use App\Models\Session;
use App\Support\Login\IpInfo;
use App\Support\Login\UserAgent;
use Illuminate\Contracts\Database\Eloquent\Builder;
use InertiaUI\Table\Action;
use InertiaUI\Table\Columns\ActionColumn;
use InertiaUI\Table\Columns\BadgeColumn;
use InertiaUI\Table\Columns\ColumnAlignment;
use InertiaUI\Table\Columns\DateColumn;
use InertiaUI\Table\Columns\TextColumn;
use InertiaUI\Table\PaginationType;
use InertiaUI\Table\Table;
use InertiaUI\Table\Variant;

class Sessions extends Table
{
    protected PaginationType $paginationType = PaginationType::Simple;

    public function resource(): Builder
    {
        return Session::query()
            ->where('user_id', auth()->user()->id)
            ->where('tenant_id', auth()->user()->tenant_id);
    }

    public function columns(): array
    {
        return [
            DateColumn::make(
                attribute: 'last_activity',
                header: __('Last Activity'),
                sortable: true,
                toggleable: false,
            ),
            TextColumn::make(
                attribute: 'ip_address',
                header: __('IP'),
                toggleable: false,
            )->mapAs(fn(string $ip) => IpInfo::getDetails($ip)),
            TextColumn::make(
                attribute: 'user_agent',
                header: __('User Agent'),
                toggleable: false,
            )->mapAs(fn(string $userAgent) => UserAgent::getDetails($userAgent)),
            BadgeColumn::make(
                attribute: 'isCurrent',
                header: __('Is Current'),
                alignment: ColumnAlignment::Right,
                toggleable: false,
            )->variant([
                true => Variant::Success,
            ]),
            ActionColumn::new()
                ->asDropdown(),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make(
                label: __('Delete'),
                icon: 'Trash',
                showLabel: false,
                disabled: fn(Session $session) => $session->isCurrent,
                after: function ($data) {
                    Session::whereIn('id', $data)
                        ->get()
                        ->each(fn(Session $session) => $session->delete());
                }
            ),
        ];
    }
}
