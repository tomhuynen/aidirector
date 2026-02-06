<?php

declare(strict_types=1);

namespace App\Tables\Admin;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InertiaUI\Modal\ModalConfig;
use InertiaUI\Modal\ModalVisit;
use InertiaUI\Table\Action;
use InertiaUI\Table\Columns\ActionColumn;
use InertiaUI\Table\Columns\BadgeColumn;
use InertiaUI\Table\Columns\ColumnAlignment;
use InertiaUI\Table\Columns\DateTimeColumn;
use InertiaUI\Table\Columns\TextColumn;
use InertiaUI\Table\Filters\DateFilter;
use InertiaUI\Table\Filters\TextFilter;
use InertiaUI\Table\QueryBuilder;
use InertiaUI\Table\Table;
use InertiaUI\Table\Url;

class Activities extends Table
{
    protected ?string $resource = Activity::class;

    protected ?string $defaultSort = '-id';

    public function __construct(
        protected ?User $user = null,
    ) {}

    public function withQueryBuilder(QueryBuilder $queryBuilder)
    {
        $queryBuilder->searchUsing(function (Builder $query, string $search, Collection $terms) {
            if (empty($search)) {
                return;
            }

            $query->where(function (Builder $query) use ($search) {
                // Search in activity fields (same database)
                $query
                    ->where('description', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('subject_type', 'like', "%{$search}%");

                // Search in causer (User) using subquery to landlord database
                $userIds = User::where(function ($userQuery) use ($search) {
                    $userQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                    ->whereNull('deleted_at')
                    ->pluck('id');

                if ($userIds->isNotEmpty()) {
                    $query->orWhereIn('causer_id', $userIds);
                }
            });
        });
    }

    public function resource(): Builder
    {
        return Activity::query()
            ->with('causer', 'subject')
            ->when($this->user, function (Builder $query) {
                $query->where('causer_id', $this->user->id);
            });
    }

    public function columns(): array
    {
        return [
            BadgeColumn::make(
                attribute: 'event',
                header: __('Action'),
                toggleable: false,
                searchable: true,
            ),
            TextColumn::make(
                attribute: 'subject_type',
                header: __('Resource Type'),
                toggleable: false,
            ),
            TextColumn::make(
                attribute: 'description',
                header: __('Description'),
                toggleable: false,
            ),
            TextColumn::make(
                attribute: 'causer',
                header: __('Causer'),
                toggleable: false,
                mapAs: fn(User $user) => $user->email,
            )->url(
                fn(Activity $activity, Url $url) => $url
                    ->route('admin.accounts.view', $activity->causer)
                    ->openInNewTab()
            ),
            DateTimeColumn::make(
                attribute: 'created_at',
                header: __('Happened At'),
                toggleable: false,
                alignment: ColumnAlignment::Right,
                sortable: true,
            )->translate(),
            ActionColumn::new(),
        ];
    }

    public function filters(): array
    {
        return [
            // Text filter
            TextFilter::make(
                attribute: 'subject_type',
                label: __('Resource Type'),
            ),
            // Date filter
            DateFilter::make(
                attribute: 'created_at',
                label: __('Happened At'),
            ),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make(
                label: __('View'),
                icon: 'Eye',
                showLabel: false,
                url: fn(Activity $activity, Url $url) => $url
                    ->route('admin.activities.view', $activity)
                    ->modal(
                        modal: ModalVisit::make()
                            ->navigate()
                            ->config(
                                config: ModalConfig::make()->slideover()
                            )
                    )
            ),
        ];
    }
}
