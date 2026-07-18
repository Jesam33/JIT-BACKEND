<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsTeacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffProfileController extends BaseLmsController
{
    public function show(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);

        return response()->json([
            'id' => $teacher->id,
            'name' => $teacher->name,
            'email' => $teacher->email,
            'phone' => $teacher->phone,
            'profile_photo_url' => $teacher->profile_photo_url,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'profile_photo_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);
        $teacher->update(array_filter($validated));

        return response()->json([
            'message' => 'Profile updated.',
            'teacher' => [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'email' => $teacher->email,
                'phone' => $teacher->phone,
                'profile_photo_url' => $teacher->profile_photo_url,
            ],
        ]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');
        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate(['file' => ['required', 'image', 'max:2048']]);

        $path = $request->file('file')->store('profile-photos', 'public');

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);
        $teacher->update(['profile_photo_url' => asset('storage/' . $path)]);

        return response()->json(['url' => asset('storage/' . $path)]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8'],
        ]);

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);

        if (! Hash::check($validated['current_password'], $teacher->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $teacher->update(['password' => Hash::make($validated['new_password'])]);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
