<?php

return [
    // ─── Tenancy (Phase 1 stabilization) ──────────────────────────────
    // The primary organisation (Jorsas Institute of Technology) is seeded as
    // Organisation 1 and owns all pre-multitenancy rows.
    'primary_slug' => env('PRIMARY_TENANT_SLUG', 'jorsas'),
    'primary_name' => env('PRIMARY_TENANT_NAME', 'Jorsas Institute of Technology'),

    // The platform's base domain (e.g. jorsastech.com). ResolveTenant strips this
    // off the request Host to read the tenant subdomain ({slug}.jorsastech.com),
    // and uses it to tell the platform's own hosts apart from a customer's custom
    // domain. Resolved HERE via config — NOT env() in the middleware — so the value
    // survives `php artisan config:cache` in production. (Reading env() directly
    // returns null once the config is cached, which silently disables subdomain
    // tenant resolution and drops every academy back to the primary tenant.) Set
    // APP_DOMAIN=jorsastech.com in the live .env; leave blank for local dev.
    'app_domain' => env('APP_DOMAIN', ''),

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

    // Master on/off for the whole student + staff LMS API (login, dashboard,
    // chat, tasks, …). Every LMS endpoint is gated by ensureLmsEnabled(); when
    // this is false the API 404s (feature hidden). Resolved HERE via config —
    // NOT env() at the call site — so the value survives `php artisan config:cache`
    // in production. (Reading env() directly in a controller returns null once
    // the config is cached, which silently 404s every LMS login.) Set
    // LMS_FEATURE_ENABLED=true in the live .env to turn the portal on.
    'lms_feature_enabled' => filter_var(env('LMS_FEATURE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // On/off for the public course-intake + registration/payment API (course
    // catalog, register, pay) and the Jorsas-primary training-registration flow.
    // Every intake endpoint is gated by ensureIntakeEnabled() /
    // ensureTrainingFeatureEnabled(); when false those endpoints 404. Resolved HERE
    // via config — NOT env() at the call site — so it survives `php artisan
    // config:cache` in production. (Reading env() directly in the controller returned
    // false once the config was cached, which silently 404'd course registration on
    // live.) Defaults ON — it's the core revenue path; TRAINING_FEATURE_ENABLED=false
    // hides it.
    'training_feature_enabled' => filter_var(env('TRAINING_FEATURE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Whether the training/agent intake sends its transactional emails (registration
    // submitted/approved, agent application submitted/approved). Same config-not-env
    // rule so it survives config:cache. Opt-in (default off) — turn on once MAIL_* is
    // configured for the environment.
    'training_email_enabled' => filter_var(env('TRAINING_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Where the "new agent application" admin notice is sent, and the admin
    // panel path used to build its review link. Read via config (not env()) at
    // the call sites so both survive `php artisan config:cache` in production.
    'training_admin_email' => env('TRAINING_ADMIN_EMAIL'),
    'admin_dir' => env('ADMIN_DIR', 'admin'),

    // On/off for the public marketing content API (FrontendContentController::home()).
    // Same config-not-env rule so it survives config:cache. Opt-in (default off).
    'frontend_api_enabled' => filter_var(env('FRONTEND_API_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

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

    // Default commission (percent of the course price) an admission agent earns on
    // a sale they refer or register. This is the platform-wide DEFAULT; each
    // academy can override it from its owner payments page (stored per-tenant in
    // settings.agent_commission_percent, read via Tenant::agentCommissionPercent).
    // It is entirely separate from the platform fee above: the platform fee is
    // taken by Paystack at settlement, while the agent commission is a ledger the
    // academy pays its own agents from. Set to 0 to default agents to no cut.
    'agent_commission_percent' => (float) env('AGENT_COMMISSION_PERCENT', 5),

    // ─── Subscription enforcement (owner plan billing) ─────────────────
    // A paid-plan academy renews monthly (manual re-checkout on the billing page;
    // there is no silent auto-charge). When its paid period ends it enters a short
    // grace window, and once the grace elapses the OWNER portal freezes to a
    // "contact management services" screen until the owner renews. Never touches
    // the primary institute, a free plan, or contact-sales Enterprise (null price),
    // and the billing + onboarding endpoints stay reachable while frozen so a
    // lapsed owner can always pay their way back in. Students are never frozen.
    //
    // Deployed DARK: the enforcement flag defaults OFF so the whole gate can ship
    // and be reviewed before it is switched on. Flip SUBSCRIPTION_ENFORCE_FREEZE=true
    // in the live .env (then config:cache) to turn freezing on. Resolved here via
    // config (never env() at the call site) so it survives config:cache.
    'subscription_enforce_freeze' => filter_var(env('SUBSCRIPTION_ENFORCE_FREEZE', false), FILTER_VALIDATE_BOOLEAN),

    // Days after a paid period ends before the owner portal freezes (grace window).
    // During grace the portal still works and the billing page nudges the owner to
    // renew; past it, the portal freezes. Default 2 days.
    'subscription_grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 2),

    // Where a frozen owner is told to reach the platform ("contact management
    // services"). Optional: when set, the freeze screen shows it as a mailto.
    // Falls back to the training admin address if that is configured. Read via
    // config so it survives config:cache.
    'support_email' => env('SUPPORT_EMAIL', env('TRAINING_ADMIN_EMAIL')),

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

    // ─── Plans, limits, per-plan commission & feature gates ─────────────
    // The self-serve tiers. Prices are NGN/month (Paystack amounts). Each plan
    // carries three tunable dimensions, all read through the Tenant model
    // (planConfig/planLimit/planFeature/commissionPercent) — never hardcoded at
    // a call site — so the whole pricing model can be retuned here alone:
    //
    //   • commission_percent — the platform's cut of every course-fee sale that
    //     settles to the institute's own Paystack subaccount (the split's
    //     `percentage_charge`). Steps down 5% → 3% → 0% as a self-upgrade nudge
    //     (Enterprise is 0% too). Applied at subaccount-creation time (Paystack
    //     freezes the split there).
    //   • limits{courses,students,staff} — hard caps enforced on create
    //     (App\Support\PlanGate). null = unlimited. Never breaks existing rows;
    //     only blocks going OVER the cap.
    //   • features{...} — boolean gates: live_classes (on EVERY plan), chat
    //     (group chat), certificates, pre_recorded_video, admission_marketer
    //     (Admission-Marketer Network), remove_branding ("Powered by Jorsas"),
    //     advanced_analytics, advanced_reporting, custom_domain, priority_support,
    //     ai_materials (Gamma AI, Pro+), api_access + white_label (Enterprise).
    //     `free` lists EVERY feature key (all false) because planConfig() merges
    //     each plan over `free` — higher tiers only override the trues.
    //
    // The primary institute (config('saas.primary_slug')) is always treated as
    // the top (Enterprise) plan regardless of what's stored — see Tenant::planConfig().
    'plans' => [
        'free' => [
            'name' => 'Free',
            // Short PDF plan verb (Start / Grow / Scale / Expand) — display only.
            'label' => 'Start',
            'price' => (float) env('PLAN_FREE_PRICE', 0),
            'commission_percent' => (float) env('PLAN_FREE_COMMISSION', 5),
            'limits' => [
                'courses' => (int) env('PLAN_FREE_MAX_COURSES', 3),
                'students' => (int) env('PLAN_FREE_MAX_STUDENTS', 1),
                'staff' => (int) env('PLAN_FREE_MAX_STAFF', 1),
                // No separate per-class cap on Free — the 1-student academy cap
                // already dominates (each course is effectively capped at 1).
                'per_course' => null,
            ],
            // `free` MUST list every feature key (all false): planConfig() merges
            // each plan over `free`, so higher tiers only override the trues.
            'features' => [
                'live_classes' => true,       // PDF: live classes on EVERY plan
                'chat' => false,
                'certificates' => false,
                'pre_recorded_video' => false,
                'admission_marketer' => false,
                // false → the storefront shows the "Powered by Jorsas" badge.
                'remove_branding' => false,
                'advanced_analytics' => false,
                'advanced_reporting' => false,
                'custom_domain' => false,
                'priority_support' => false,
                'ai_materials' => false,
                'api_access' => false,
                'white_label' => false,
            ],
        ],
        'basic' => [
            'name' => 'Basic',
            'label' => 'Grow',
            'price' => (float) env('PLAN_BASIC_PRICE', 5000),
            'commission_percent' => (float) env('PLAN_BASIC_COMMISSION', 3),
            'limits' => [
                'courses' => (int) env('PLAN_BASIC_MAX_COURSES', 10),
                // Unlimited platform students from Basic up (null = unlimited);
                // the cap that actually bites is per-class, below.
                'students' => null,
                'staff' => (int) env('PLAN_BASIC_MAX_STAFF', 5),
                // Max students per class (per course) on Basic.
                'per_course' => (int) env('PLAN_BASIC_MAX_PER_COURSE', 30),
            ],
            'features' => [
                'live_classes' => true,
                'chat' => true,
                'certificates' => true,
                // Pre-recorded (on-demand) video is a Pro+ feature — Free & Basic
                // are live-classes only. Explicit false (not just inherited from
                // `free`) so the intent reads clearly here.
                'pre_recorded_video' => false,
                'admission_marketer' => true,
                'remove_branding' => true,
                // Basic gets standard analytics; "advanced" analytics is Pro+.
                'advanced_analytics' => false,
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'label' => 'Scale',
            'price' => (float) env('PLAN_PRO_PRICE', 15000),
            'commission_percent' => (float) env('PLAN_PRO_COMMISSION', 0),
            'limits' => [
                'courses' => (int) env('PLAN_PRO_MAX_COURSES', 50),
                // Unlimited platform students (null); the per-class cap bites.
                'students' => null,
                'staff' => (int) env('PLAN_PRO_MAX_STAFF', 25),
                // Max students per class (per course) on Pro.
                'per_course' => (int) env('PLAN_PRO_MAX_PER_COURSE', 250),
            ],
            'features' => [
                'live_classes' => true,
                'chat' => true,
                'certificates' => true,
                'pre_recorded_video' => true,
                'admission_marketer' => true,
                'remove_branding' => true,
                'advanced_analytics' => true,
                'advanced_reporting' => true,
                'custom_domain' => true,
                'priority_support' => true,
                'ai_materials' => true,       // Gamma AI — Pro and up
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'label' => 'Expand',
            // Custom pricing — Enterprise is "Contact Sales", never self-serve
            // (TenantSignupController only provisions free/basic/pro).
            'price' => null,
            'contact_sales' => true,
            'commission_percent' => (float) env('PLAN_ENTERPRISE_COMMISSION', 0),
            'limits' => [
                // null = unlimited / custom capacity.
                'courses' => null,
                'students' => null,
                'staff' => null,
                // Uncapped per class too (top tier).
                'per_course' => null,
            ],
            'features' => [
                'live_classes' => true,
                'chat' => true,
                'certificates' => true,
                'pre_recorded_video' => true,
                'admission_marketer' => true,
                'remove_branding' => true,
                'advanced_analytics' => true,
                'advanced_reporting' => true,
                'custom_domain' => true,
                'priority_support' => true,
                'ai_materials' => true,
                'api_access' => true,
                'white_label' => true,
            ],
        ],
    ],
];
