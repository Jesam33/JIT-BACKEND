<?php

namespace App\Http\Controllers\Lms;

use App\Services\LiveQuizzes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "This class is being recorded": the teacher's free recorder (frontend
 * lib/class-recorder, which records the class tab in their own browser and
 * saves the file to their computer) flips this on while it runs, and every
 * student's class screen shows a "● Recording" badge so nobody is recorded
 * without knowing. Nothing is uploaded or stored by us: the flag lives in the
 * cache and expires on its own if the teacher's browser vanishes mid-recording.
 */
class StaffClassRecordingController extends BaseLmsController
{
    public const TTL_HOURS = 4;

    public static function cacheKey(string $type, int $id): string
    {
        return "class-recording:{$type}:{$id}";
    }

    public static function isRecording(string $type, int $id): bool
    {
        return Cache::has(static::cacheKey($type, $id));
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $actor = $this->staffActor($request);
        if (! $actor) {
            abort(401, 'Unauthorized');
        }

        $data = $request->validate([
            'class_type' => ['required', 'in:scheduled,classroom'],
            'class_id' => ['required', 'integer'],
            'on' => ['required', 'boolean'],
        ]);

        // Only the teacher's own classes (the owner: any in the academy).
        $courseId = LiveQuizzes::classCourseId($data['class_type'], (int) $data['class_id']);
        abort_unless($courseId && in_array($courseId, array_map('intval', $this->actorCourseIds($actor)), true), 404, 'Class not found.');

        $key = static::cacheKey($data['class_type'], (int) $data['class_id']);
        if ($data['on']) {
            Cache::put($key, now()->toIso8601String(), now()->addHours(self::TTL_HOURS));
        } else {
            Cache::forget($key);
        }

        return response()->json(['recording' => (bool) $data['on']]);
    }
}
