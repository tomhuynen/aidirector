<?php

declare(strict_types=1);

namespace App\Support\Search\Searchables;

use App\Support\Search\Contracts\Searchable;
use Illuminate\Database\Eloquent\Model;

class Resolver
{
    /**
     * @param  class-string<Model & Searchable>  $model
     */
    public function resolve(string $model): BaseSearchable
    {
        return $this->createSearchable($model);
    }

    protected function createSearchable(string $model): BaseSearchable
    {
        $class = $this->getHandlerClass($model);

        return new $class($model);
    }

    /**
     * @param  class-string  $model
     *
     * @return class-string<BaseSearchable>
     */
    protected function getHandlerClass(string $model)
    {
        $handlerNamespaces = $this->getNamespaces();
        $classBasename = class_basename($model);

        foreach ($handlerNamespaces as $namespace) {
            $handlerClass = $namespace . $classBasename . 'Searchable';

            if (class_exists($handlerClass)) {
                return $handlerClass;
            }
        }

        return DefaultSearchable::class;
    }

    protected function getNamespaces(): array
    {
        $configNamespaces = config('search.namespaces', []);
        $defaultNamespaces = [
            'App\\Support\\Search\\Searchables\\',
        ];

        // Config namespaces first (allow overrides), then defaults
        return array_merge($configNamespaces, $defaultNamespaces);
    }
}
