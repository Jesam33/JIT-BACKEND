<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsCertificate;
use App\Models\LmsEnrollment;
use App\Models\LmsModule;
use App\Models\LmsStudent;
use App\Models\LmsTrack;
use App\Models\LmsCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StudentProfileController extends BaseLmsController
{
    public function me(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $student = LmsStudent::query()->findOrFail($session->user_id);

        // Merge the tenant plan so the student shell can gate paid-only features
        // (chat) without a second round-trip, plus a `tenant` descriptor so the
        // portal re-pins its `tenant` cookie from THIS authenticated session on
        // every load, otherwise the inactivity → login redirect falls back to
        // the primary slug and re-login hits the wrong institute. The model is
        // returned as-is otherwise, preserving every field the frontend reads.
        return response()->json(array_merge($student->toArray(), [
            'plan' => $this->planForSession($session),
            'tenant' => $this->tenantPayloadForSession($session),
        ]));
    }

    public function profile(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $student = LmsStudent::query()->findOrFail($session->user_id);
        $enrollment = LmsEnrollment::query()->where('student_id', $student->id)->first();
        $track = $enrollment ? LmsTrack::query()->find($enrollment->track_id) : null;
        $courseId = $student->selected_course_id ?? $track?->course_id;
        $course = $courseId ? LmsCourse::query()->find($courseId) : null;

        $totalModules = 0;
        if ($courseId) {
            $totalModules = LmsModule::query()->where('course_id', $courseId)->count();
        }

        return response()->json([
            'id' => $student->id,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'email' => $student->email,
            'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
            'phone' => $student->phone,
            'gender' => $student->gender,
            'profile_photo_url' => $student->profile_photo_url,
            'notify_class_reminders' => $student->notify_class_reminders,
            'notify_chat' => $student->notify_chat,
            'notify_announcements' => $student->notify_announcements,
            'referral_code' => 'KC-STD-' . str_pad($student->id, 4, '0', STR_PAD_LEFT),
            'course_title' => $course?->title,
            'track_name' => $track?->name,
            'total_modules' => $totalModules,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', 'string', 'max:20'],
            'profile_photo_url' => ['nullable', 'string', 'max:2048'],
            'notify_class_reminders' => ['nullable', 'boolean'],
            'notify_chat' => ['nullable', 'boolean'],
            'notify_announcements' => ['nullable', 'boolean'],
        ]);

        $student = LmsStudent::query()->findOrFail($session->user_id);

        $student->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'date_of_birth' => $validated['date_of_birth'] ?? $student->date_of_birth,
            'phone' => $validated['phone'] ?? $student->phone,
            'gender' => $validated['gender'] ?? $student->gender,
            'profile_photo_url' => $validated['profile_photo_url'] ?? $student->profile_photo_url,
            'notify_class_reminders' => (bool) ($validated['notify_class_reminders'] ?? false),
            'notify_chat' => (bool) ($validated['notify_chat'] ?? false),
            'notify_announcements' => (bool) ($validated['notify_announcements'] ?? false),
        ]);

        $enrollment = LmsEnrollment::query()->where('student_id', $student->id)->first();
        $track = $enrollment ? LmsTrack::query()->find($enrollment->track_id) : null;
        $courseId = $student->selected_course_id ?? $track?->course_id;
        $course = $courseId ? LmsCourse::query()->find($courseId) : null;

        return response()->json([
            'message' => 'Profile updated.',
            'profile' => [
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'email' => $student->email,
                'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
                'phone' => $student->phone,
                'gender' => $student->gender,
                'profile_photo_url' => $student->profile_photo_url,
                'notify_class_reminders' => $student->notify_class_reminders,
                'notify_chat' => $student->notify_chat,
                'notify_announcements' => $student->notify_announcements,
                'referral_code' => 'KC-STD-' . str_pad($student->id, 4, '0', STR_PAD_LEFT),
                'course_title' => $course?->title,
                'track_name' => $track?->name,
            ],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $student = LmsStudent::query()->findOrFail($session->user_id);

        if (! Hash::check($validated['current_password'], $student->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $student->update(['password' => Hash::make($validated['new_password'])]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');
        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate(['file' => ['required', 'image', 'max:2048']]);

        $path = $request->file('file')->store('profile-photos', 'public');

        $student = LmsStudent::query()->findOrFail($session->user_id);
        // Store the RELATIVE path (the model mutator normalises it); the accessor
        // rebuilds an absolute URL against the current host on read, so the image
        // can't break when the app host changes (localhost → live, http → https).
        $student->update(['profile_photo_url' => $path]);

        return response()->json(['url' => $student->profile_photo_url]);
    }

    public function certificates(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $certificates = LmsCertificate::query()
            ->where('student_id', $session->user_id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($certificates);
    }
}
