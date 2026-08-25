<?php

return [
    // ─── Tenancy (Phase 1 stabilization) ──────────────────────────────
    // The primary organisation (Jorsas Institute of Technology) is seeded as
    // Organisation 1 and owns all pre-multitenancy rows.
    'primary_slug' => env('PRIMARY_TENANT_SLUG', 'jorsas'),
    'primary_name' => env('PRIMARY_TENANT_NAME', 'Jorsas Institute of Technology'),

    // Header names accepted by ResolveTenant for public/unauthenticated requests.
    // Both are honoured so the existing frontend server components (X-Tenant-Slug)
    // and any legacy callers (X-Tenant) keep working.
    'tenant_headers' => ['X-Tenant', 'X-Tenant-Slug'],

    // Slugs that can never be a tenant — they collide with infra subdomains
    // (www, api, mail, …) or app-level paths. Signup rejects them and
    // ResolveTenant refuses to treat them as a tenant subdomain.
    'reserved_slugs' => [
        'www', 'api', 'app', 'admin', 'mail', 'smtp', 'ftp',
        'static', 'assets', 'cdn', 'img', 'images', 'media',
        'dashboard', 'billing', 'signup', 'login', 'onboarding',
        'support', 'help', 'docs', 'blog', 'status', 'default',
    ],

    // Fail-closed master switch. When false (default), ResolveTenant keeps the
    // legacy 'default' fallback and RequireTenant does not abort — so code can be
    // deployed and the migration/backfill run before enforcement is turned on.
    // Flip to true (ENFORCE_TENANCY=true) only AFTER migrate + tenants:establish-primary
    // + the frontend login-header change are live.
    'enforce_tenancy' => filter_var(env('ENFORCE_TENANCY', false), FILTER_VALIDATE_BOOLEAN),

    // Public URL of the Next.js frontend, used to build links in outgoing emails
    // (staff/student invites, password resets, payment callbacks, owner setup).
    // Resolved here — via config, NOT env() at the call sites — so the value
    // survives `php artisan config:cache` in production. In production set
    // LMS_BASE_URL (or FRONTEND_URL) to your public site, e.g.
    // https://jorsastech.com; otherwise every emailed link points at localhost
    // and is dead on arrival for the recipient.
    'frontend_url' => rtrim((string) env('LMS_BASE_URL', env('FRONTEND_URL', 'http://127.0.0.1:3000')), '/'),

    // Platform's commission (percent) retained on every course-fee payment that
    // settles to an institute's own Paystack subaccount. The remainder settles to
    // the institute's bank. Set to 0 to take no cut. Applied at subaccount
    // creation time (Paystack `percentage_charge`).
    'platform_commission_percent' => (float) env('PLATFORM_COMMISSION_PERCENT', 2),

    // Define available plans and prices (NGN assumed for Paystack amounts)
    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0.00,
        ],
        'basic' => [
            'name' => 'Basic',
            'price' => 5000.00,
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 15000.00,
        ],
    ],
];
