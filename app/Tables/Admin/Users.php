<?php

declare(strict_types=1);

namespace App\Tables\Admin;

use App\Models\Tenant;
use App\Models\User;
use App\Tables\Admin\Filters\SoftDeletes as FiltersSoftDeletes;
use Illuminate\Contracts\Database\Eloquent\Builder;
use InertiaUI\Table\Action;
use InertiaUI\Table\Columns\ActionColumn;
use InertiaUI\Table\Columns\ColumnAlignment;
use InertiaUI\Table\Columns\DateColumn;
use InertiaUI\Table\Columns\TextColumn;
use InertiaUI\Table\Filters\BooleanFilter;
use InertiaUI\Table\Table;

class Users extends Table
{
    public function resource(): Builder
    {
        return User::query()
            ->with('tenant')
            ->where('tenant_id', Tenant::current()->id);
    }

    public function columns(): array
    {
        return [
            TextColumn::make(
                attribute: 'name',
                header: __('Name'),
                sortable: true,
                searchable: true,
            ),
            TextColumn::make(
                attribute: 'email',
                header: __('Email'),
                sortable: true,
                searchable: true,
            ),
            TextColumn::make(
                attribute: 'tenant.name',
                header: __('Tenant'),
                sortable: true,
                searchable: true,
            ),
            DateColumn::make(
                attribute: 'created_at',
                header: __('Created At'),
                sortable: true,
                alignment: ColumnAlignment::Right
            ),
            ActionColumn::new()
                ->asDropdown(),
        ];
    }

    public function filters(): array
    {
        return [
            BooleanFilter::make(
                attribute: 'deleted_at',
                label: __('Show Trashed'),
            )
                ->applyUsing(new FiltersSoftDeletes(), true),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make(
                label: __('Restore'),
                icon: 'ArchiveRestore',
                hidden: fn(User $user) => ! $user->trashed(),
                after: function (array $data) {
                    User::withTrashed()
                        ->whereIn('id', $data)
                        ->get()
                        ->each(fn(User $user) => $user->restore());
                }
            ),
            Action::make(
                label: __('Delete'),
                icon: 'Trash',
                hidden: fn(User $user) => ! $user->trashed(),
                after: function (array $data) {
                    User::withTrashed()
                        ->whereIn('id', $data)
                        ->get()
                        ->each(fn(User $user) => $user->forceDelete());
                }
            ),
            Action::make(
                label: __('Update'),
                icon: 'Pencil',
                showLabel: false,
            )->url(fn(User $user) => route('admin.accounts.update', $user)),
            Action::make(
                label: __('View'),
                icon: 'Eye',
                showLabel: false,
            )->url(fn(User $user) => route('admin.accounts.view', $user)),
            Action::make(
                label: __('Invite'),
                icon: 'Send',
                showLabel: false,
                hidden: fn(User $user) => $user->isMe(),
            )->url(fn(User $user) => route('admin.accounts.invite', $user)),
            Action::make(
                label: __('Impersonate'),
                icon: 'Drama',
                showLabel: false,
                hidden: fn(User $user) => $user->isMe() || ! $user->canBeImpersonated(),
            )->url(fn(User $user) => route('admin.impersonate', $user->id)),
        ];
    }
}
