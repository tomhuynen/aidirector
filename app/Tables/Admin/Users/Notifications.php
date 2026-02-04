<?php

declare(strict_types=1);

namespace App\Tables\Admin\Users;

use App\Models\NotificationLogItem;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InertiaUI\Table\Columns\ColumnAlignment;
use InertiaUI\Table\Columns\DateColumn;
use InertiaUI\Table\Columns\TextColumn;
use InertiaUI\Table\Table;

class Notifications extends Table
{
    protected ?array $perPageOptions = [10];

    public function __construct(
        public User $user,
    ) {}

    public function resource(): Builder
    {
        return NotificationLogItem::query()
            ->whereMorphedTo('notifiable', $this->user);
    }

    public function columns(): array
    {
        return [
            TextColumn::make(
                attribute: 'notification_type',
                header: __('Notification'),
                toggleable: false,
            ),
            DateColumn::make(
                attribute: 'created_at',
                header: __('Sent At'),
                toggleable: false,
                alignment: ColumnAlignment::Right,
            ),
        ];
    }

    /** @param NotificationLogItem $model */
    public function transformModel(Model $model, array $data): array
    {
        return [
            ...$data,
            'notification_type' => $model->name(),
        ];
    }
}
