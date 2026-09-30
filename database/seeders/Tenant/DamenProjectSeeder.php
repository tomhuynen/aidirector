<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Models\Director;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * The first project: a safety and security induction for everyone entering
 * the shipyard of Damen, a Dutch shipbuilder. Seeds the project only; shots
 * are added in the app. Attached to the first director, or a demo director
 * when none exists.
 */
class DamenProjectSeeder extends Seeder
{
    public function run(): void
    {
        $director = Director::query()->orderBy('id')->first()
            ?? Director::factory()->create(['name' => 'Demo director', 'email' => 'director@example.com']);

        Project::query()->firstOrCreate(
            ['director_id' => $director->id, 'title' => 'Damen'],
            [
                'purpose' => ProjectPurpose::E_LEARNING,
                'description' => 'Safety and security induction for Damen, a Dutch shipbuilder. Everyone entering the shipyard, whether employee, visitor, supplier or customer, learns how to arrive, move around and act safely on site. Bilingual (NL/EN), one idea per shot.',
                'aspect_ratio' => AspectRatio::PORTRAIT,
                'default_duration' => 6,
                'style' => [
                    'look' => 'Clean corporate 3D illustration, soft shading, minimal detail, simple facial features, no text in frame',
                    'palette' => 'Navy blue and steel grey shipyard tones, safety yellow accents for vests, signs and highlights, light neutral background',
                    'medium' => '3D illustration',
                    'mood' => 'Calm, reassuring, professional',
                    'references' => [],
                ],
            ],
        );
    }
}
