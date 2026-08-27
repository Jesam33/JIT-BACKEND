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

    // ─── Notification emails ───────────────────────────────────────────
    // Every in-app notification (and every platform announcement) is also
    // emailed to the recipient by the scheduled `lms:send-notification-emails`
    // sweep. Master switch: set NOTIFICATION_EMAILS_ENABLED=false to keep the
    // in-app notifications but stop emailing them (e.g. before MAIL_* is set up).
    'notification_emails_enabled' => filter_var(env('NOTIFICATION_EMAILS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Notification `type`s that are created in-app but NOT emailed. Chat @mentions
    // are real-time and high-volume, so emailing each one is noise — excluded by
    // default. Clear this list (or drop 'mention') to email those too.
    'notification_email_exclude_types' => array_filter(array_map(
        'trim',
        explode(',', (string) env('NOTIFICATION_EMAIL_EXCLUDE_TYPES', 'mention'))
    )),

    // How many notification emails one sweep run sends, per recipient table.
    // Keeps a single cron tick bounded on sync mail / shared hosting; the backlog
    // drains over subsequent minutes. Raise if you have a faster mail transport.
    'notification_email_batch' => (int) env('NOTIFICATION_EMAIL_BATCH', 120),

    // Give up emailing a notification after this many failed attempts (so a
    // permanently-bad address does not get retried forever every minute).
    'notification_email_max_attempts' => (int) env('NOTIFICATION_EMAIL_MAX_ATTEMPTS', 3),


    // Platform's commission (percent) retained on every course-fee payment that
    // settles to an institute's own Paystack subaccount. The remainder settles to
    // the institute's bank. Set to 0 to take no cut. Applied at subaccount
    // creation time (Paystack `percentage_charge`).
    'platform_commission_percent' => (float) env('PLATFORM_COMMISSION_PERCENT', 2),

    // Who bears Paystack's transaction fee (~1.5%) on split payments to an
    // institute's subaccount. `subaccount` (default) = the institute absorbs it
    // (status quo); `account` = the platform (main account) absorbs it. Applied
    // as Paystack's `bearer` on transaction initialize. Only ever a knob — the
    // default keeps today's behavior byte-for-byte.
    'paystack_fee_bearer' => env('PAYSTACK_FEE_BEARER', 'subaccount'),

    // Charge non-Nigerian buyers in USD (Paystack). OFF by default: leave it off
    // until the platform's Paystack account is confirmed USD-enabled — while off,
    // EVERY buyer is charged NGN (today's behavior) regardless of their country.
    // The display price is always localized for the visitor either way; this flag
    // only governs the currency money is actually collected in.
    'usd_charge_enabled' => filter_var(env('USD_CHARGE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Geo + FX for localized DISPLAY pricing (never affects the charged amount).
    // Free, no-key providers by default; both degrade gracefully (manual currency
    // selector + base-NGN fallback) so the storefront never breaks if they're down.
    'geo_ip_provider_url' => env('GEO_IP_PROVIDER_URL', 'https://ipwho.is/'),
    'fx_provider_url' => env('FX_PROVIDER_URL', 'https://open.er-api.com/v6/latest/NGN'),
    'fx_cache_minutes' => (int) env('FX_CACHE_MINUTES', 60),

    // ─── Storefront "Bestseller" badge ─────────────────────────────────
    // A course earns the teal Bestseller badge only when it is the most-enrolled
    // ACTIVE course in its institute AND its real enrollment (registered_count)
    // has cleared this floor. New/low-traction institutes stay below the floor
    // and show no badge — the badge is never fabricated. Set to 0 to badge the
    // top course regardless of volume (still requires ≥1 enrollment to be "top").
    'bestseller_min_enrollments' => (int) env('BESTSELLER_MIN_ENROLLMENTS', 10),

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
