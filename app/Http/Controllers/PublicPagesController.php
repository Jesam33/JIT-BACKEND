<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PublicPagesController extends Controller
{
    public function signup()
    {
        return view('public.signup');
    }

    public function plans()
    {
        return view('public.plans');
    }

    public function onboardingStatus()
    {
        return view('public.onboarding');
    }

    // simple JSON endpoint for available plans, sourced from config/saas.php
    // (the plans DB table + admin CRUD are dormant; config is the source of truth)
    public function plansJson()
    {
        $plans = collect(config('saas.plans', []))
            ->map(fn ($plan, $slug) => [
                'slug' => $slug,
                'name' => $plan['name'] ?? ucfirst($slug),
                'price' => (float) ($plan['price'] ?? 0),
            ])
            ->values();

        return response()->json($plans);
    }

    // Resolve tenant by host or slug. Accepts ?host=example.tenant.com or ?slug=tenant-slug
    public function resolveTenant(Request $request)
    {
        $host = $request->query('host');
        $slug = $request->query('slug');

        if (! $slug && $host) {
            // extract subdomain if host looks like sub.example.com
            $hostOnly = preg_replace('/:\\d+$/', '', $host);
            $parts = explode('.', $hostOnly);
            if (count($parts) >= 3) {
                $slug = $parts[0];
            }
        }

        if (! $slug) {
            return response()->json(['found' => false], 404);
        }

        $tenant = \App\Models\Tenant::where('slug', $slug)->first();
        if (! $tenant) {
            return response()->json(['found' => false], 404);
        }

        return response()->json([
            'found' => true,
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'settings' => $tenant->settings ?? new \stdClass(),
            ],
        ]);
    }

    // Return onboarding audit rows and an overall status for a tenant
    public function onboardingStatusJson(Request $request)
    {
        $tenantId = $request->query('tenant');
        if (! $tenantId) {
            return response()->json(['error' => 'tenant required'], 400);
        }

        $rows = \DB::table('tenant_onboarding_audits')->where('tenant_id', $tenantId)->orderBy('created_at')->get();

        $last = $rows->last();
        // Use a friendlier default status when no onboarding audit rows exist.
        $status = $last?->status ?? 'pending';

        return response()->json([
            'status' => $status,
            'audits' => $rows,
        ]);
    }

    // Owner summary: returns basic tenant info, owner email and onboarding status.
    public function ownerSummary(Request $request)
    {
        $tenantId = $request->query('tenant');
        $tenantSlug = $request->query('slug');

        if (! $tenantId && ! $tenantSlug) {
            return response()->json(['error' => 'tenant or slug required'], 400);
        }

        $tenantQuery = \App\Models\Tenant::query();
        if ($tenantId) {
            $tenantQuery->where('id', $tenantId);
        } else {
            $tenantQuery->where('slug', $tenantSlug);
        }

        $tenant = $tenantQuery->first();
        if (! $tenant) {
            return response()->json(['error' => 'tenant not found'], 404);
        }

        // Require either an owner session token (Authorization: Bearer, preferred),
        // the legacy X-Lms-Token header, or an owner invite token (query param 'token').
        $accessToken = $request->query('token') ?? $request->header('X-Lms-Token') ?? $request->bearerToken();
        if (! $accessToken) {
            return response()->json(['error' => 'access token required'], 403);
        }

        $allowed = false;
        // Check owner invitation token
        $inv = \App\Models\OwnerInvitation::where('token', $accessToken)->where('tenant_id', $tenant->id)->first();
        if ($inv && (! $inv->expires_at || ! $inv->expires_at->isPast())) {
            $allowed = true;
        }

        // Check owner session token. This is a self-authorizing platform route
        // with no bound tenant, so the lookup must bypass the tenant scope; the
        // explicit tenant_admins membership check below is what authorizes.
        if (! $allowed) {
            $sess = \App\Models\LmsSession::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('token', $accessToken)->where('expires_at', '>', now())->first();
            if ($sess && in_array($sess->role, ['owner', 'admin'])) {
                $isAdmin = \DB::table('tenant_admins')->where('tenant_id', $tenant->id)->where('user_id', $sess->user_id)->exists();
                if ($isAdmin) {
                    $allowed = true;
                }
            }
        }

        if (! $allowed) {
            return response()->json(['error' => 'not authorized'], 403);
        }

        $ownerRow = \DB::table('tenant_admins')->where('tenant_id', $tenant->id)->first();
        $owner = $ownerRow ? \App\Models\User::find($ownerRow->user_id) : null;

        $rows = \DB::table('tenant_onboarding_audits')->where('tenant_id', $tenant->id)->orderBy('created_at')->get();
        $last = $rows->last();
        $status = $last?->status ?? 'pending';

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'created_at' => $tenant->created_at,
            ],
            'owner' => [
                'email' => $owner?->email,
                'name' => $owner?->name,
            ],
            'onboarding' => [
                'status' => $status,
                'audits' => $rows,
            ],
        ]);
    }
}
