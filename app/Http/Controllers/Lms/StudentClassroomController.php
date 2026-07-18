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
use Illuminate\Support\Facades\Log;

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

        $launchUrl = $classroom->meeting_url;

        if (! $launchUrl && $classroom->meeting_id) {
            $launchUrl = 'https://zoom.us/j/' . $classroom->meeting_id;
        }

        if ($launchUrl) {
            LmsAttendance::query()->firstOrCreate(
                ['student_id' => $session->user_id, 'classroom_id' => $classroom->id],
                ['joined_at' => now()]
            );
        }

        return response()->json([
            'launch_url' => $launchUrl,
            'meeting_id' => $classroom->meeting_id,
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

        $launchUrl = $classroom->meeting_url ?? ($classroom->meeting_id ? 'https://zoom.us/j/' . $classroom->meeting_id : null);

        if (! $launchUrl) {
            return response()->json(['message' => 'No meeting available for this classroom.'], 404);
        }

        return response()->json(['launch_url' => $launchUrl, 'meeting_id' => $classroom->meeting_id]);
    }

    public function sdkSignature(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $classroom = LmsClassroom::query()->find($id);
        $scheduled = null;

        if (! $classroom) {
            $scheduled = LmsScheduledClass::query()->findOrFail($id);
            if (! $scheduled->meeting_id) {
                return response()->json(['message' => 'No meeting ID configured for this class.'], 400);
            }
        } elseif (! $classroom->meeting_id) {
            return response()->json(['message' => 'No meeting ID configured for this classroom.'], 400);
        }

        $sdkKey = config('services.zoom.sdk_key');
        $sdkSecret = config('services.zoom.sdk_secret');

        if (! $sdkKey || ! $sdkSecret) {
            return response()->json(['message' => 'Zoom SDK not configured.'], 500);
        }

        $student = \App\Models\LmsStudent::query()->findOrFail($session->user_id);

        $iat = now()->timestamp;
        $exp = now()->addHours(2)->timestamp;

        $meetingNumber = (int) preg_replace('/\s+/', '', $classroom ? $classroom->meeting_id : $scheduled->meeting_id);

        $payload = [
            'appKey' => $sdkKey,
            'sdkKey' => $sdkKey,
            'mn' => $meetingNumber,
            'role' => 0,
            'iat' => $iat,
            'exp' => $exp,
            'tokenExp' => $exp,
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $signature = $this->jwtEncodeHs256($header, $payload, $sdkSecret);

        if ($classroom) {
            LmsAttendance::query()->firstOrCreate(
                ['student_id' => $session->user_id, 'classroom_id' => $classroom->id],
                ['joined_at' => now()]
            );
        }

        $passcode = $classroom ? $classroom->meeting_password : ($scheduled->meeting_password ?? '');

        return response()->json([
            'signature' => $signature,
            'sdk_key' => $sdkKey,
            'meeting_number' => $meetingNumber,
            'user_name' => trim($student->first_name . ' ' . $student->last_name) ?: 'Student',
            'user_email' => $student->email,
            'passcode' => $passcode,
        ]);
    }

    public function zoomWebhook(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $payload = $request->all();
        $event = $payload['event'] ?? '';

        Log::info('Zoom webhook received', ['event' => $event, 'payload' => $payload]);

        if ($event === 'meeting.participant_joined') {
            $meetingId = (string) ($payload['payload']['object']['id'] ?? '');
            $participantUserId = (string) ($payload['payload']['object']['participant']['user_id'] ?? '');

            if ($meetingId && $participantUserId) {
                $classroom = LmsClassroom::query()->where('meeting_id', $meetingId)->first();

                if ($classroom) {
                    $student = \App\Models\LmsStudent::query()
                        ->where('zoom_user_id', $participantUserId)
                        ->first();

                    if ($student) {
                        $existing = LmsAttendance::query()
                            ->where('student_id', $student->id)
                            ->where('classroom_id', $classroom->id)
                            ->first();

                        if ($existing) {
                            $existing->update(['joined_at' => now()]);
                        } else {
                            LmsAttendance::query()->create([
                                'student_id' => $student->id,
                                'classroom_id' => $classroom->id,
                                'joined_at' => now(),
                                'first_joined_at' => now(),
                            ]);
                        }
                    }
                }
            }
        }

        if ($event === 'meeting.participant_left') {
            $meetingId = (string) ($payload['payload']['object']['id'] ?? '');
            $participantUserId = (string) ($payload['payload']['object']['participant']['user_id'] ?? '');

            if ($meetingId && $participantUserId) {
                $classroom = LmsClassroom::query()->where('meeting_id', $meetingId)->first();

                if ($classroom) {
                    $student = \App\Models\LmsStudent::query()
                        ->where('zoom_user_id', $participantUserId)
                        ->first();

                    if ($student) {
                        $attendance = LmsAttendance::query()
                            ->where('student_id', $student->id)
                            ->where('classroom_id', $classroom->id)
                            ->whereNull('last_left_at')
                            ->first();

                        if ($attendance) {
                            if (! $attendance->first_joined_at) {
                                $attendance->update([
                                    'first_joined_at' => $attendance->joined_at,
                                    'last_left_at' => now(),
                                ]);
                            } else {
                                $attendance->update(['last_left_at' => now()]);
                            }
                        }
                    }
                }
            }
        }

        if ($event === 'meeting.ended') {
            $meetingId = (string) ($payload['payload']['object']['id'] ?? '');

            if ($meetingId) {
                $classroom = LmsClassroom::query()->where('meeting_id', $meetingId)->first();

                if ($classroom) {
                    $attendances = LmsAttendance::query()
                        ->where('classroom_id', $classroom->id)
                        ->whereNull('calculated_at')
                        ->get();

                    foreach ($attendances as $attendance) {
                        $totalSeconds = 0;

                        $firstJoined = $attendance->first_joined_at ?? $attendance->joined_at;

                        if ($firstJoined) {
                            $leftAt = $attendance->last_left_at ?? now();
                            $totalSeconds = max(0, Carbon::parse($leftAt)->diffInSeconds($firstJoined));
                        }

                        $durationMinutes = (int) round($totalSeconds / 60);

                        $classDurationMinutes = $classroom->starts_at && $classroom->ends_at
                            ? (int) round($classroom->starts_at->diffInMinutes($classroom->ends_at))
                            : 60;

                        $threshold = max(1, (int) round($classDurationMinutes * 0.75));

                        $status = $durationMinutes >= $threshold
                            ? 'present'
                            : ($durationMinutes > 0 ? 'partial' : 'absent');

                        $attendance->update([
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
                    }
                }
            }
        }

        return response()->json(['message' => 'Webhook processed.']);
    }

    private function jwtEncodeHs256(array $header, array $payload, string $secret): string
    {
        $segments = [];
        $segments[] = rtrim(strtr(base64_encode(json_encode($header, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $segments[] = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return implode('.', $segments);
    }
}
