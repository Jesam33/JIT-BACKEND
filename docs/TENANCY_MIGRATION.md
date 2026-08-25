Tenancy Migration and Deployment Steps

1. Backup production database before any changes.

2. Run migrations (staging first):

```
php artisan migrate --env=staging
```

3. Create default tenant and assign existing rows (staging):

```
php artisan tenants:assign-default --env=staging
```

The command creates a tenant with slug `default` if missing and updates all rows where `tenant_id IS NULL` to the default tenant id.

4. Verify staging app behaviour: sign in as admin, check course lists, student lists, and ensure behaviour unchanged.

5. Add `tenant_id` to models as shown in `app/Traits/TenantAware.php` and use `app()->instance('currentTenant', $tenant)` for CLI tasks.

6. After verification, create a migration to make `tenant_id` non-nullable and add FK constraints, then run in production during a maintenance window.

7. Monitor logs and metrics for any anomalies.
