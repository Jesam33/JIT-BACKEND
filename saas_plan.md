# JorsasTech → SaaS Plan

Goal: turn the existing Jorsas single-institute LMS into a multi-tenant, white-label SaaS platform where any institute can run their own branded school on the same codebase.

## Current state (inventory of what we already have)

- **Backend**: Laravel 12 + Botble CMS, custom LMS stack
  - Models: `LmsCourse`, `LmsStudent`, `LmsTeacher`, `LmsTrack`, `LmsBatch`, `LmsClassroom`, `LmsModule`, `LmsMaterial`, `LmsTask`, `LmsEnrollment`, `LmsAttendance*`, `LmsCertificate`, `Chat (LmsMessage/DmThread/GroupChat)`, `Agent*` (commissions/withdrawals), `Paystack payments`
  - Portals: student (dashboard, modules, timetable, tasks, grading, attendance, certs, chat), staff/teacher (module/content authoring, scheduling, classes via Zoom, tasks, attendance, certs, announcements, reports), agent (referrals, commissions, withdrawals), admin (Botble backend: intake/registration approval, courses, tracks, batches, classrooms, students, agents, payouts)
  - 68 migrations in `database/migrations`
  - Payments: Paystack init/verify/webhook
  - Real-time chat: Laravel Reverb + WebSockets
  - Classrooms: Zoom Meeting SDK integration
- **Frontend**: `jorsas-tech-v2/` — Next.js SPA (React 19, Tailwind 4, Redux, Redux-Thunk, pusher-js, Zoom Meeting SDK)
- **Hard-wired single-tenant assumptions (what we must break):**
  - No `tenant_id` anywhere (problem solved by DB-per-tenant — no schema changes needed)
  - Single `.env` config, system-wide super admin
  - Shared Zoom/Pusher credentials

## Decisions locked (from brainstorming)

| Topic | Decision |
|---|---|
| Tenancy model | **DB-per-tenant** — one full MySQL database per institute |
| Product model | Managed core + **white-label & custom domain/branding** |
| Billing | Start with **flat tiered subscriptions** (see below), not per-seat or commission cuts |
| JIT role | **Seed tenant** — migrate Jorsas as tenant #1 and run it live (dogfood) |

## Revenue model — recommended

- **Flat tiered subscriptions** via Paystack recurring (start here — predictable, simple to build, no payment-processor liability)
- Do **NOT** take a commission cut of institute course fees initially (regulatory + gateway pain in Nigeria)

Plans (draft):
- **Starter**: 1 course, 100 students, platform subdomain
- **Pro**: 10 courses, 1,000 students, agents + Zoom
- **Scale**: unlimited courses/students, white-label custom domain, priority support
- 14-day free trial on Pro, then paid

Aside: per-seat metering and commission-cut can be added later without redesign.

## Architecture (DB-per-tenant + white-label)

```
 Operator hub (control plane)                     Tenant app
 ┌──────────────────────────────┐                 ┌─────────────────────┐
 │ Control-plane DB             │  resolves       │ MySQL DB per tenant │
 │ saas_tenants, domains,       │                 │ ALL existing LMS    │
 │ subscriptions, invoices,     │   tenant DB     │ tables UNCHANGED    │
 │ operator_users, audit_logs   │                 │ students, staff,    │
 └──────────────┬───────────────┘   └───────────► │ agents, courses,    │
                │                                 │ chat                │
 ┌──────────────┴───────────┐                     └─────────▲───────────┘
 │ ResolveTenant middleware │ ◄── host → tenant lookup      │
 └──────────────────────────┘            Request host ──────┘
                                   Next.js app (white-label SPA)
```

**Two datastores:**

1. **Control plane DB** (small, central): `tenants` (name, slug, plan, status), `domains` (custom_domain, kind, verified), `subscriptions`, `invoices`, `operator_users`, `audit_logs`. Only the operator (your team) reads/writes this.

2. **Tenant DB** — one full per-institute MySQL DB. Contains **exactly the current tables, unmodified**. All 68 migrations run per tenant. Payoff: the entire existing LMS codebase (students, staff, agents, courses, tasks, chat, certs, Paystack) works on this connection with **zero schema changes**.

**Runtime flow:**

- `ResolveTenant` middleware derives tenant from the host (custom domain first, else `slug.jitsaas.com`), looks up control plane (cached), resolves the tenant connection name.
- The Laravel `tenant` connection is set lazily at boot. All Eloquent models already use the `default` connection name; we make their configured connection dynamic per-tenant so no per-model changes are needed.
- Queue workers manually set the tenant connection at job start from the job payload.

**White-label & domains:**

- Every tenant gets a free subdomain; can map any custom domain via DNS CNAME.
- Per-tenant branding stored as JSON in a tenant settings table: `institute_name`, `logo`, `primary_color`, `accent_color`, `copyright`, support email, currency, etc.
- Next.js SPA renders branding from endpoint `/api/saas/theme` (token-safe) instead of hard-coded site config.
- Domains map: `sites` table → route TLS (Let's Encrypt), so each tenant keeps SSL.

## Build list — net new ≈ 10% of product effort vs. rework

Mostly-built and reusable (~90% of product):
- Whole student portal, staff portal, agent-commission system
- All migrations/tables, chat, Zoom classrooms, Payments, certificates
- Next.js frontend pages (only need branding/theming hooks)

**New components (the actual SaaS infra):**

1. `app/Tenancy` — core tenancy passport: `Tenant` (control-plane model), `TenancyService` (boot with `tenant_` connection), `TenantNotFound` exception
2. Control-plane schema: `tenants`, `domains`, `plans`, `subscriptions`, `invoices`, `operator_users`, `audit_logs`
3. (optional) domain resolution middleware + caching
4. Migration-based tenant provisioning — queue job per tenant: build DB, run migrations, seed defaults (default roles, demo track/course)
5. Onboarding wizard (Next.js): institute name/logo, Paystack keys, Zoom credentials, custom domain, theme pick
6. Billing — plans table + Paystack recurring, billing-gate middleware enforcing plan limits, trial windows
7. Per-tenant backups + monitoring/DR
8. Admin/operator console UI for the team (list tenants, statistics, per-tenant actions, force provision/suspend, etc.)

## Phases / implementation order

- **Phase 0 (≈2–3 wks)**: control-plane schema; Tenant-connection resolution; domain routing; provisioning job; prototype a brand new tenant + site
- **Phase 1**: migrate JIT to tenant #1 (seed tenant); white-label/theme per domain; run it live (JIT dogfoods the platform)
- **Phase 2**: Paystack subscriptions, plans & limits, trials, billing gates
- **Phase 3**: sign-up/onboarding flow; provisioning queue; per-tenant backups; monitoring/DR; operator console
- **Phase 4**: launch self-serve — sign up, pay, provisioning, analytics, etc.

## Notes / selected design

- **JIT = seed tenant (dogfood)**: migrate our current `jorsastech_local` to `tenant_jit`; keep a rollback path (env flag pointing back to the old standalone).
- **Cap cost**: many tenant DBs is fine — each DB is small and cheap; cap provisioning to what you can maintain.
- **No per-tenant schema drift**: one migration set, single codebase; provisioning runs migrations per tenant at build time.
- **Zoom/Pusher credentials are per-tenant secrets** — never shared across tenants.

## Unmade decisions (future)

- Row-level tenancy (shared DB) for thousands of tenants later — a known migration path, not now.
- Per-seat pricing and commission-sharing for the agent program.
- Bring-your-own domain TLS (SAN / Let's Encrypt proxying) at scale.

---

_Living document — keep in repo, update as phases absorb._