<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsTeacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffProfileController extends BaseLmsController
{
    /**
     * The acting teacher (a staffer's own row, or the academy owner's mirror) and
     * the owner's real login account when an owner is acting.
     *
     * The mirror holds the teaching identity (the display name that appears on
     * rosters, the phone, the photo) but NOT a usable login: it has a random
     * password nobody holds and a synthetic internal address. So the owner's real
     * email is reported in its place, and changePassword() refuses rather than
     * pretending to rotate a password that can't be signed in with.
     *
     * @return array{0: LmsTeacher|null, 1: \App\Models\User|null}
     */
    private function actorPair(Request $request): array
    {
        $actor = $this->staffActor($request);

        if (! $actor) {
            return [null, null];
        }

        return [$actor, $actor->isAcademyOwner() ? $this->ownerFromRequest($request) : null];
    }

    private function payload(LmsTeacher $teacher, ?\App\Models\User $owner = null): array
    {
        return [
            'id' => $teacher->id,
            'name' => $teacher->name,
            // Never surface the mirror's synthetic address: the owner signs in with
            // the account email below.
            'email' => $owner?->email ?? $teacher->email,
            'phone' => $teacher->phone,
            'profile_photo_url' => $teacher->profile_photo_url,
            'academy_owner' => $teacher->isAcademyOwner(),
            // Where a sign-in password is actually changed; the staff page hides its
            // password form when this is set.
            'password_managed_at' => $owner ? '/lms/admin/profile' : null,
        ];
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        [$teacher, $owner] = $this->actorPair($request);

        if (! $teacher) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($this->payload($teacher, $owner));
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        [$teacher, $owner] = $this->actorPair($request);

        if (! $teacher) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'profile_photo_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $teacher->update(array_filter($validated));

        return response()->json([
            'message' => 'Profile updated.',
            'teacher' => $this->payload($teacher, $owner),
        ]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        [$teacher] = $this->actorPair($request);
        if (! $teacher) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate(['file' => ['required', 'image', 'max:2048']]);

        $path = $request->file('file')->store('profile-photos', 'public');

        // Store the RELATIVE path (the model mutator normalises it); the accessor
        // rebuilds an absolute URL against the current host on read, so the image
        // can't break when the app host changes (localhost → live, http → https).
        $teacher->update(['profile_photo_url' => $path]);

        return response()->json(['url' => $teacher->profile_photo_url]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        [$teacher, $owner] = $this->actorPair($request);

        if (! $teacher) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // The owner's mirror has no usable login, so there is no staff password to
        // rotate. Point at the real account page instead of failing on a hash the
        // owner can never supply.
        if ($owner) {
            return response()->json([
                'message' => 'Your sign-in password is changed from your owner Profile page, not here.',
                'password_managed_at' => '/lms/admin/profile',
            ], 422);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8'],
        ]);

        if (! Hash::check($validated['current_password'], $teacher->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $teacher->update(['password' => Hash::make($validated['new_password'])]);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
