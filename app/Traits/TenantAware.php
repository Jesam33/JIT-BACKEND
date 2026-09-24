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

    /**
     * Create a row explicitly for $tenantId, even when a different tenant is bound.
     *
     * `tenant_id` is deliberately absent from every model's $fillable, because it is
     * never something a request should be able to set. That makes the obvious
     * `create(['tenant_id' => $x])` a silent no-op: mass assignment drops the key,
     * the `creating` hook above then sees an empty tenant_id and stamps whichever
     * tenant happens to be bound. On the platform back office — where the primary
     * tenant is bound so TenantAware doesn't fail closed — that means every row the
     * host creates for another academy lands on the primary one instead, with no
     * error to notice.
     *
     * forceFill() is the point of this method, not an accident: it is the only way
     * to set a guarded attribute without opening tenant_id to mass assignment
     * everywhere else.
     *
     * @see \App\Models\LmsCourse  the host back office's academy picker
     */
    public static function createForTenant(int $tenantId, array $attributes): static
    {
        $model = new static($attributes);
        $model->forceFill(['tenant_id' => $tenantId])->save();

        return $model;
    }

    /**
     * Find-or-create keyed on $attributes, filing a new row under $tenantId.
     *
     * The same trap as createForTenant(): firstOrCreate()'s second argument is mass
     * assigned, so passing tenant_id there is dropped on create. The lookup also has
     * to drop the scope — the row may legitimately belong to another academy than
     * the bound one, and a scoped lookup would miss it and then try to create a
     * duplicate.
     */
    public static function firstOrCreateForTenant(int $tenantId, array $attributes, array $values = []): static
    {
        $existing = static::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where($attributes)
            ->first();

        return $existing ?: static::createForTenant($tenantId, array_merge($attributes, $values));
    }
}
