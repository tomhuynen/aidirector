<?php

declare(strict_types=1);

namespace App\Tables\Admin;

use App\Models\Tenant;
use InertiaUI\Table\Action;
use InertiaUI\Table\Columns\ActionColumn;
use InertiaUI\Table\Columns\ColumnAlignment;
use InertiaUI\Table\Columns\DateColumn;
use InertiaUI\Table\Columns\TextColumn;
use InertiaUI\Table\Table;

class Tenants extends Table
{
    protected ?string $resource = Tenant::class;

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
                attribute: 'domain',
                header: __('Domain'),
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

    public function actions(): array
    {
        return [
            Action::make(
                label: __('View'),
                icon: 'Eye',
                showLabel: false,
            )->url(fn(Tenant $tenant) => route('admin.tenants.view', $tenant)),
            Action::make(
                label: __('Update'),
                icon: 'Pencil',
                showLabel: false,
            )->url(fn(Tenant $tenant) => route('admin.tenants.update', $tenant)),
        ];
    }
}
