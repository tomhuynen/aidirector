<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Planned keyframes no longer have a separate "must be clearly visible" line: the line is appended to the
 * description of the plan and of the drawn keyframe, so nothing the director wrote is lost.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('shots')->whereNotNull('storyline')->orderBy('id')->each(function (object $shot) {
            $storyline = json_decode((string) $shot->storyline, true);

            if (! is_array($storyline) || ! is_array($storyline['keyframes'] ?? null)) {
                return;
            }

            $changed = false;
            $keyframes = array_values($storyline['keyframes']);

            foreach ($keyframes as $index => $keyframe) {
                if (! is_array($keyframe) || ! array_key_exists('must_show', $keyframe)) {
                    continue;
                }

                $changed = true;
                $mustShow = trim((string) $keyframe['must_show']);
                unset($keyframes[$index]['must_show']);

                if ($mustShow === '') {
                    continue;
                }

                $keyframes[$index]['description'] = $this->appended((string) ($keyframe['description'] ?? ''), $mustShow);

                $row = DB::table('keyframes')->where('shot_id', $shot->id)->where('position', $index + 1)->first(['id', 'description']);

                if ($row !== null) {
                    DB::table('keyframes')->where('id', $row->id)->update(['description' => $this->appended((string) $row->description, $mustShow)]);
                }
            }

            if ($changed) {
                DB::table('shots')->where('id', $shot->id)->update(['storyline' => json_encode([...$storyline, 'keyframes' => $keyframes])]);
            }
        });
    }

    private function appended(string $description, string $mustShow): string
    {
        $description = trim($description);

        if (str_contains($description, $mustShow)) {
            return $description;
        }

        $sentence = rtrim($mustShow, '.') . '.';

        if ($description === '') {
            return $sentence;
        }

        return (preg_match('/[.!?]$/', $description) === 1 ? $description : $description . '.') . ' ' . $sentence;
    }
};
