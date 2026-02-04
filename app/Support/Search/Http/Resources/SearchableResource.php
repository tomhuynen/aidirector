<?php

declare(strict_types=1);

namespace App\Support\Search\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string|null $sqid
 * @property int $id
 * @property string $name
 */
class SearchableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid ?? $this->id,
            'name' => $this->name,
        ];
    }
}
