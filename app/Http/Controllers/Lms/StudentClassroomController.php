<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsAttendance;
use App\Models\LmsAttendanceEvent;
use App\Models\LmsAttendanceRecord;
use App\Models\LmsClassroom;
use App\Models\LmsScheduledClass;
use App\Models\LmsSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentClassroomController extends BaseLmsController
{
    public function join(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classroom = LmsClassroom::query()->findOrFail($id);

        if (! $this->canStudentJoinClassroom($classroom)) {
            return response()->json(['message' => 'You cannot join this class yet.'], 403);
        }

        // "Mark attendance only" — record the join without launching the embedded
        // room. Live classes run on Jitsi (joined in-portal), so there is no longer
        // an external meeting URL to open here.
        LmsAttendance::query()->firstOrCreate(
            ['student_id' => $session->user_id, 'classroom_id' => $classroom->id],
            ['joined_at' => now(), 'first_joined_at' => now()]
        );

        return response()->json([
            'recorded' => true,
            'message' => 'Attendance recorded.',
        ]);
    }

    public function launch(Request $request, int $id)
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classroom = LmsClassroom::query()->findOrFail($id);

        // Live classes are now joined in-portal (embedded Jitsi), so there is no
        // external launch URL. Point callers at the in-portal classroom page.
        return response()->json([
            'launch_url' => rtrim((string) config('saas.frontend_url'), '/') . '/lms/app/classroom',
            'meeting_id' => $classroom->meeting_id,
        ]);
    }

    public function sdkSignature(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Live classes are a paid-plan feature. Gate on the bound tenant's plan
        // (ResolveTenantFromSession bound it from the bearer token) BEFORE the
        // platform config check, so a free institute is told to upgrade rather
        // than shown a misleading "not configured". The primary institute — and
        // any tier that includes live_classes — passes through.
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        if (! $tenant || ! $tenant->planFeature('live_classes')) {
            return response()->json([
                'message' => 'Live classes are a paid-plan feature. Ask your institute to upgrade to join live sessions.',
                'feature' => 'live_classes',
                'upgrade_required' => true,
            ], 402);
        }

        $cfg = $this->jitsiConfig();

        if (! $cfg) {
            return response()->json(['message' => 'Live classes are not configured yet.'], 503);
        }

        $classType = $request->input('class_type', 'classroom');
        $classroom = null;

        if ($classType === 'scheduled') {
            $scheduled = LmsScheduledClass::query()->findOrFail($id);
            $room = $this->ensureRoom($scheduled, 'scheduled');
        } else {
            $classroom = LmsClassroom::query()->findOrFail($id);
            $room = $this->ensureRoom($classroom, 'classroom');
        }

        $student = \App\Models\LmsStudent::query()->findOrFail($session->user_id);
        $userName = trim($student->first_name . ' ' . $student->last_name) ?: 'Student';

        $jwt = $this->mintJaasToken($cfg, $room, [
            'id' => 'student-' . $student->id,
            'name' => $userName,
            'email' => $student->email,
        ], false);

        // Only classroom-type classes track attendance (scheduled classes never did).
        if ($classroom) {
            LmsAttendance::query()->firstOrCreate(
                ['student_id' => $session->user_id, 'classroom_id' => $classroom->id],
                ['joined_at' => now(), 'first_joined_at' => now()]
            );
        }

        return response()->json([
            'room' => $room,
            'jwt' => $jwt,
            'domain' => $cfg['domain'],
            'app_id' => $cfg['appId'],
            'user_name' => $userName,
            'moderator' => false,
        ]);
    }

    public function attendanceLeave(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Only classroom-type classes track attendance (scheduled classes never did).
        $classType = $request->input('class_type', 'classroom');

        if ($classType === 'scheduled') {
            return response()->json(['recorded' => false]);
        }

        $classroom = LmsClassroom::query()->findOrFail($id);

        $attendance = LmsAttendance::query()
            ->where('student_id', $session->user_id)
            ->where('classroom_id', $classroom->id)
            ->first();

        if (! $attendance) {
            return response()->json(['recorded' => false]);
        }

        $firstJoined = $attendance->first_joined_at ?? $attendance->joined_at ?? now();
        $leftAt = now();
        $totalSeconds = max(0, Carbon::parse($leftAt)->diffInSeconds(Carbon::parse($firstJoined)));

        $durationMinutes = (int) round($totalSeconds / 60);

        $classDurationMinutes = $classroom->starts_at && $classroom->ends_at
            ? (int) round($classroom->starts_at->diffInMinutes($classroom->ends_at))
            : 60;

        $threshold = max(1, (int) round($classDurationMinutes * 0.75));

        $status = $durationMinutes >= $threshold
            ? 'present'
            : ($durationMinutes > 0 ? 'partial' : 'absent');

        $attendance->update([
            'first_joined_at' => $firstJoined,
            'last_left_at' => $leftAt,
            'total_seconds' => $totalSeconds,
            'status' => $status,
            'calculated_at' => now(),
        ]);

        LmsAttendanceRecord::query()->updateOrCreate(
            [
                'classroom_id' => $classroom->id,
                'student_id' => $attendance->student_id,
            ],
            [
                'total_seconds' => $totalSeconds,
                'first_joined_at' => $firstJoined,
                'status' => $status,
                'calculated_at' => now(),
            ]
        );

        return response()->json([
            'recorded' => true,
            'status' => $status,
            'total_seconds' => $totalSeconds,
        ]);
    }
}
