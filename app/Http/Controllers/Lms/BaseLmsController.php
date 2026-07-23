<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\LmsClassroom;
use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsPasswordReset;
use App\Models\LmsSession;
use App\Models\LmsTrack;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class BaseLmsController extends Controller
{
    protected function ensureLmsEnabled(): void
    {
        if (! filter_var(env('LMS_FEATURE_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new NotFoundHttpException();
        }
    }

    protected function ensureSuperAdmin(Request $request): void
    {
        $user = $request->user();
        $isSuperUser = $user && method_exists($user, 'isSuperUser') && $user->isSuperUser();

        abort_unless($isSuperUser, 403, 'Only super admin can perform this action.');
    }

    protected function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return trim(substr($header, 7));
    }

    protected function sessionFromRequest(Request $request, string $role): ?LmsSession
    {
        $token = $this->bearerToken($request);

        if (! $token) {
            return null;
        }

        return LmsSession::query()
            ->where('token', $token)
            ->where('role', $role)
            ->where('expires_at', '>', now())
            ->first();
    }

    protected function classroomJoinOpensAt(LmsClassroom $classroom)
    {
        return $classroom->starts_at?->copy()->subMinutes(5);
    }

    protected function canStudentJoinClassroom(LmsClassroom $classroom): bool
    {
        if (! $classroom->starts_at) {
            return false;
        }

        $joinOpensAt = $this->classroomJoinOpensAt($classroom);

        if (! $joinOpensAt || now()->lt($joinOpensAt)) {
            return false;
        }

        return ! $classroom->ends_at || now()->lte($classroom->ends_at);
    }

    protected function ensureStudentTrackContext(int $studentId): array
    {
        $enrollment = LmsEnrollment::query()->where('student_id', $studentId)->first();

        if (! $enrollment) {
            abort(422, 'Student is not enrolled in a track yet.');
        }

        $track = LmsTrack::query()->findOrFail($enrollment->track_id);
        $groupChat = LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);
        $dmThread = LmsDmThread::query()->firstOrCreate([
            'student_id' => $studentId,
            'instructor_id' => $track->instructor_id,
            'track_id' => $track->id,
        ]);

        return [$track, $groupChat, $dmThread];
    }

    protected function findActiveTrackForCourse(?int $courseId): ?LmsTrack
    {
        if (! $courseId) return null;

        $now = now();

        $tracks = LmsTrack::query()->with('batch')
            ->where('course_id', $courseId)
            ->orderBy('id')
            ->get();

        foreach ($tracks as $t) {
            if (! $t->batch) {
                return $t;
            }

            $starts = $t->batch->registration_starts_at;
            $ends = $t->batch->registration_ends_at;

            if ($starts && $now->lt($starts)) {
                continue;
            }

            if ($ends && $now->gt($ends)) {
                continue;
            }

            return $t;
        }

        return null;
    }

    protected function createPasswordResetToken(string $role, string $email): string
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        LmsPasswordReset::query()->where('role', $role)->where('email', $email)->delete();

        LmsPasswordReset::query()->create([
            'role' => $role,
            'email' => $email,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addMinutes(30),
        ]);

        return $token;
    }

    protected function isValidResetToken(string $role, string $email, string $token): bool
    {
        $tokenHash = hash('sha256', $token);

        return LmsPasswordReset::query()
            ->where('role', $role)
            ->where('email', $email)
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    protected function consumeResetToken(string $role, string $email, string $token): void
    {
        $tokenHash = hash('sha256', $token);

        LmsPasswordReset::query()
            ->where('role', $role)
            ->where('email', $email)
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    protected function buildResetLink(string $role, string $email, string $token): string
    {
        $baseUrl = rtrim((string) env('LMS_BASE_URL', 'http://127.0.0.1:3000'), '/');
        $path = match ($role) {
            'staff' => '/lms/staff/reset-password',
            'agent' => '/lms/agent/reset-password',
            default => '/lms/reset-password',
        };

        return $baseUrl . $path . '?email=' . urlencode($email) . '&token=' . urlencode($token);
    }

    protected function extractPasscodeFromUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $parsed = parse_url($url);

        if ($parsed && ! empty($parsed['query'])) {
            parse_str($parsed['query'], $query);

            if (! empty($query['pwd'])) {
                return $query['pwd'];
            }
        }

        return null;
    }

    protected function maybeFillPasscode(array &$data): void
    {
        if (empty($data['meeting_password']) && ! empty($data['meeting_url'])) {
            $extracted = $this->extractPasscodeFromUrl($data['meeting_url']);

            if ($extracted) {
                $data['meeting_password'] = $extracted;
            }
        }
    }

    protected function adminShellData(): array
    {
        return [
            'adminDir' => env('ADMIN_DIR', 'admin'),
            'pendingRegistrations' => \App\Models\TrainingRegistration::query()->where('status', 'pending')->count(),
            'studentCount' => \App\Models\LmsStudent::query()->count(),
            'tracks' => LmsTrack::query()->count(),
            'courses' => \App\Models\LmsCourse::query()->count(),
            'teachers' => \App\Models\LmsTeacher::query()->count(),
            'classrooms' => LmsClassroom::query()->count(),
        ];
    }
}
