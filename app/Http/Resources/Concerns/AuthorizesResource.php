<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

trait AuthorizesResource
{
    /**
     * Generate the authorization array for the resource.
     *
     * @param  array<string>  $abilities  Policy constants to check
     * @return array<string, bool>
     */
    protected function authorizations(Request $request, array $abilities): array
    {
        if (is_null($request->user())) {
            return [];
        }

        return collect($abilities)->mapWithKeys(
            fn(string $ability) => [$ability => $request->user()->can($ability, $this->resource)]
        )->all();
    }
}
