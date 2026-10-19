<?php

declare(strict_types=1);

use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scene or close-up has a start frame and an end frame, timed by the
 * planner. A shot drawn with more keyframes is planned down to those two
 * and drawn again from its plan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            $table->dropColumn('seconds');
        });

        Shot::query()->whereNotIn('kind', [ShotKind::MONTAGE, ShotKind::PRESENTER, ShotKind::INSERT])->orWhereNull('kind')->get()
            ->filter(fn(Shot $shot) => count($shot->storylineKeyframes()) > 2 || $shot->keyframes()->count() > 2)
            ->each(function (Shot $shot) {
                $planned = $shot->storylineKeyframes();

                $shot->keyframes()->get()->each(fn(Keyframe $keyframe) => $keyframe->delete());
                $shot->clearMediaCollection(Shot::VIDEO);
                $shot->clearMediaCollection(Shot::VIDEO_ORIGINAL);

                $shot->forceFill([
                    'storyline' => [
                        ...($shot->storyline ?? []),
                        'keyframes' => count($planned) > 2 ? [$planned[0], $planned[count($planned) - 1]] : $planned,
                    ],
                    'keyframe_review' => null,
                    'video_prompt' => null,
                    'video_summary' => null,
                    'video_job_id' => null,
                    'video_error' => null,
                    'status' => ShotStatus::STORYLINE_READY,
                ])->save();
            });
    }

    public function down(): void
    {
        Schema::table('keyframes', function (Blueprint $table) {
            $table->decimal('seconds', 4, 1)->nullable()->after('position');
        });
    }
};
