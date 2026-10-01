<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Media;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reference photo of a project, with its caption and a signed link.
 *
 * @mixin Media
 */
class ReferenceImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conversion = $this->hasGeneratedConversion(Project::REFERENCE) ? Project::REFERENCE : null;

        return [
            'id' => $this->sqid,
            /** @var string|null */
            'caption' => $this->getCustomProperty(Project::CAPTION),
            'url' => $this->signedUrl($conversion),
        ];
    }
}
