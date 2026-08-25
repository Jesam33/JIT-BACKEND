<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if ($tenant && isset($tenant->id)) {
            $builder->where($model->getTable() . '.tenant_id', $tenant->id);
            return;
        }

        // No tenant bound. Console/queue contexts (seeders, backfill, onboarding
        // jobs) legitimately run unscoped, so no-op there. On the web path, fail
        // closed once enforcement is on: return zero rows rather than leak across
        // tenants. While enforcement is off this stays a no-op, so deploying this
        // change does not alter current behaviour until the cutover flag flips.
        if (! app()->runningInConsole() && config('saas.enforce_tenancy')) {
            $builder->whereRaw('1 = 0');
        }
    }
}
