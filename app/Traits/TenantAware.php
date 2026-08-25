<?php

namespace App\Traits;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

trait TenantAware
{
    public static function bootTenantAware(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model) {
            $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
            if ($tenant && empty($model->tenant_id)) {
                $model->tenant_id = $tenant->id;
                return;
            }

            // Fail closed: never persist a tenant-less row on the web path once
            // enforcement is on. Console/queue and rows with an explicit tenant_id
            // are exempt. This catches the class of bug behind the old onboarding
            // "unbind then create" workaround.
            if (empty($model->tenant_id) && ! app()->runningInConsole() && config('saas.enforce_tenancy')) {
                throw new \RuntimeException('Cannot create ' . get_class($model) . ' without a tenant context.');
            }
        });
    }

    public function scopeWithTenant($query, $tenantId)
    {
        return $query->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId);
    }
}
