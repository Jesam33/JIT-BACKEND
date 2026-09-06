<?php

namespace App\Http\Controllers\Lms;

use App\Models\OwnerInvitation;
use App\Models\User;
use App\Models\LmsSession;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OwnerAuthController extends BaseLmsController
{
    public function invite(Request $request): JsonResponse
    {
        $token = (string) $request->query('token', '');
        if (! $token) {
            return response()->json(['message' => 'token required'], 400);
        }

        $inv = OwnerInvitation::query()->where('token', $token)->first();
        if (! $inv) {
            return response()->json(['message' => 'Invalid or expired token.'], 404);
        }

        if ($inv->expires_at && $inv->expires_at->isPast()) {
            return response()->json(['message' => 'Expired token.'], 410);
        }

        $tenant = \App\Models\Tenant::find($inv->tenant_id);

        return response()->json([
            'email' => $inv->email,
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug] : null,
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $inv = OwnerInvitation::query()->where('token', $validated['token'])->first();
        if (! $inv) {
            return response()->json(['message' => 'Invalid or expired setup token.'], 404);
        }

        if ($inv->expires_at && $inv->expires_at->isPast()) {
            return response()->json(['message' => 'Expired token.'], 410);
        }

        DB::beginTransaction();
        try {
            $user = User::query()->firstOrCreate(
                ['email' => $inv->email],
                ['username' => $inv->email, 'first_name' => '', 'last_name' => '', 'password' => Hash::make($validated['password'])]
            );

            // update password if existing
            if (! Hash::check($validated['password'], $user->password)) {
                $user->update(['password' => Hash::make($validated['password'])]);
            }

            // ensure tenant_admins record exists
            if (! DB::table('tenant_admins')->where('tenant_id', $inv->tenant_id)->where('user_id', $user->id)->exists()) {
                DB::table('tenant_admins')->insert([
                    'tenant_id' => $inv->tenant_id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                ]);
            }

            $inv->used_at = now();
            $inv->save();

            // The invitation is the authoritative proof of which organisation
            // this owner belongs to. Bind it so the session row is stamped with
            // the correct tenant_id (User itself is a cross-tenant principal and
            // is intentionally not tenant-scoped).
            $this->bindTenantFromModel($inv);

            // create session token for owner
            $sessionToken = Str::random(80);
            LmsSession::query()->create([
                'role' => 'owner',
                'user_id' => $user->id,
                'token' => $sessionToken,
                'expires_at' => now()->addDays(7),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to complete setup.'], 500);
        }

        // Now that the owner has a password + session, tell them their institute
        // is ready. Deliberately sent HERE (not during paid-signup provisioning)
        // so it arrives AFTER the "set up your account" invite, and its
        // "Go to dashboard" link now actually works. Best-effort and fully
        // decoupled from the transaction above: a mail failure must never fail
        // setup or roll it back.
        try {
            $tenant = \App\Models\Tenant::find($inv->tenant_id);
            if ($tenant) {
                $user->notify(new \App\Notifications\OnboardingCompleted($tenant));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to send onboarding-complete notification after owner setup', ['err' => $e->getMessage()]);
        }

        return response()->json(['token' => $sessionToken]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant_id' => ['nullable', 'integer'],
            'tenant_slug' => ['nullable', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        // check tenant membership
        $tenantId = $validated['tenant_id'] ?? null;
        if (! $tenantId && ! empty($validated['tenant_slug'])) {
            $tenant = \App\Models\Tenant::where('slug', $validated['tenant_slug'])->first();
            $tenantId = $tenant?->id;
        }

        if (! $tenantId) {
            return response()->json(['message' => 'tenant_id or tenant_slug required'], 400);
        }

        $isAdmin = DB::table('tenant_admins')->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists();
        if (! $isAdmin) {
            return response()->json(['message' => 'User is not an owner for this tenant.'], 403);
        }

        // Bind the validated tenant (membership already confirmed above) so the
        // owner session row is stamped with its tenant_id.
        $tenant = \App\Models\Tenant::find($tenantId);
        if ($tenant) {
            app()->instance('currentTenant', $tenant);
        }

        $sessionToken = Str::random(80);
        LmsSession::query()->create([
            'role' => 'owner',
            'user_id' => $user->id,
            'token' => $sessionToken,
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(['token' => $sessionToken]);
    }
}
