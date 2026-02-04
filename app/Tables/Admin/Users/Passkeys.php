<?php

declare(strict_types=1);

namespace App\Tables\Admin\Users;

use App\Models\Passkey;
use Illuminate\Contracts\Database\Eloquent\Builder;
use InertiaUI\Table\Action;
use InertiaUI\Table\Columns\ActionColumn;
use InertiaUI\Table\Columns\BooleanColumn;
use InertiaUI\Table\Columns\ColumnAlignment;
use InertiaUI\Table\Columns\DateColumn;
use InertiaUI\Table\Columns\TextColumn;
use InertiaUI\Table\PaginationType;
use InertiaUI\Table\Table;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class Passkeys extends Table
{
    protected PaginationType $paginationType = PaginationType::Simple;

    public function resource(): Builder
    {
        return Passkey::query()
            ->where('authenticatable_id', auth()->user()->id);
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
            DateColumn::make(
                attribute: 'created_at',
                header: __('Created At'),
                sortable: true,
                alignment: ColumnAlignment::Right
            ),
            DateColumn::make(
                attribute: 'last_used_at',
                header: __('Last Used At'),
                sortable: true,
                alignment: ColumnAlignment::Right
            ),
            BooleanColumn::make(
                attribute: 'data->backupEligible',
                header: __('Backup Eligible'),
                sortable: true,
                alignment: ColumnAlignment::Right
            ),
            TextColumn::make(
                attribute: 'data->transports',
                header: __('Transports'),
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
                label: __('Delete'),
                icon: 'Trash',
                showLabel: false,
                handle: function (Passkey $passkey) {
                    if ($passkey->authenticatable_id !== auth()->user()->id) {
                        throw new BadRequestException();
                    }

                    $passkey->delete();
                }
            )->asDangerButton(),
        ];
    }
}
