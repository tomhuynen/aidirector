<?php

declare(strict_types=1);

namespace App\Support\Search\Http\Controllers\Search;

use App\Support\Search\Contracts\Searchable;
use App\Support\Search\Http\Resources\SearchableResource;
use App\Support\Search\Searchables\Resolver as SearchableResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ViewController
{
    /**
     * List all chat messages for a given entity.
     *
     * @return SearchableResource
     */
    public function view(Request $request, string $entity, string|int $id, SearchableResolver $resolver)
    {
        $morphs = collect(Relation::morphMap())->filter(function ($class) {
            return Auth::user()->can('view', new $class());
        });

        $class = $morphs->get($entity);

        abort_unless(isset($class), 404);
        abort_unless(in_array(Searchable::class, class_implements($class)), 404);

        /** @var class-string<Model & Searchable> $class */
        $searchable = $resolver->resolve($class);

        return $searchable->find($request, $id);
    }
}
