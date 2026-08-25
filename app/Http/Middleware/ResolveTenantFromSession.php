<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Tenant;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\Agent;
use App\Models\AgentSession;
use App\Scopes\TenantScope;

class ResolveTenantFromSession
{
    /**
     * If the request carries a valid bearer token, the tenant is derived from
     * that session/agent-session and becomes authoritative — overriding any
     * header- or subdomain-derived tenant. A header that resolved to a DIFFERENT
     * real tenant than the session is rejected as a cross-tenant attempt.
     *
     * Runs after ResolveTenant and before RequireTenant.
     */
    public function handle($request, Closure $next)
    {
        $token = $this->bearerToken($request);

        if ($token) {
            $tenantId = $this->tenantIdForToken($token);

            if ($tenantId) {
                $tenant = Tenant::find($tenantId);

                if ($tenant) {
                    $requested = app()->bound('requestedTenantSlug') ? app('requestedTenantSlug') : null;
                    if ($requested && $requested !== $tenant->slug) {
                        abort(403, 'Tenant mismatch.');
                    }

                    app()->instance('currentTenant', $tenant);
                }
            }
        }

        return $next($request);
    }

    /**
     * Look up the token across both session tables WITHOUT the tenant scope, so
     * the session determines the tenant rather than the reverse. Prefers the
     * tenant_id stamped on the session; when that is null (legacy sessions, or
     * sessions minted before login bound a tenant) it falls back to the session
     * USER's own tenant_id, so the portal still resolves the right organisation.
     */
    private function tenantIdForToken(string $token): ?int
    {
        $session = LmsSession::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if ($session) {
            return $session->tenant_id ?? $this->tenantIdForSessionUser($session);
        }

        $agentSession = AgentSession::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if ($agentSession) {
            return $agentSession->tenant_id ?? optional(
                Agent::withoutGlobalScope(TenantScope::class)->find($agentSession->agent_id)
            )->tenant_id;
        }

        return null;
    }

    /**
     * The tenant_id of the account a session belongs to, read past the tenant
     * scope. Used only to repair sessions whose own tenant_id is null.
     */
    private function tenantIdForSessionUser(LmsSession $session): ?int
    {
        $model = match ($session->role) {
            'student' => LmsStudent::class,
            'staff', 'teacher' => LmsTeacher::class,
            default => null,
        };

        if (! $model) {
            return null;
        }

        return optional(
            $model::query()->withoutGlobalScope(TenantScope::class)->find($session->user_id)
        )->tenant_id;
    }

    private function bearerToken($request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return trim(substr($header, 7));
    }
}
