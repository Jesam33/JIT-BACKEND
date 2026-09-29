# JorsasTech SaaS: how the platform works

A multi-tenant, white-label LMS. Any academy ("institute") signs up, pays, and runs its own branded school on one shared codebase and database. Jorsas (JIT) is the primary institute and runs on the same platform.

_Last reviewed 2026-09-29. This replaces the original plan, which proposed one database per tenant; that design was not built._

## Stack

| Part | Where | What |
|---|---|---|
| Backend | repo root | Laravel 12 + Botble CMS (the super-admin back office), PHP 8.2 to 8.4, MySQL |
| Frontend | `jorsas-tech-v2/` (its own git repo) | Next.js 16, React 19, Tailwind 4 |
| Payments | Paystack | Pay-first signup, plan subscriptions, course fees split to each academy's own bank |
| Live classes | 8x8 JaaS (Jitsi) | Replaced Zoom |
| Realtime chat | Laravel Reverb / Pusher, polling fallback | `BROADCAST_CONNECTION=null` falls back to polling |
| Video lessons | Bunny Stream | Pre-recorded courses |
| AI materials | Gamma | Pro and above |

## Tenancy

**One shared database.** Tenant-owned tables carry a `tenant_id` column, and models using the `TenantAware` trait are filtered by a global `TenantScope` that fails closed (no bound tenant, no rows).

How the tenant is resolved for a request:

- `ResolveTenant`: subdomain (`{slug}.jorsastech.com`), a verified custom domain, or the `X-Tenant-Slug` header.
- `ResolveTenantFromSession`: a logged-in portal request takes its tenant from the bearer session, which wins over any header.
- Public storefronts (`/i/{slug}`) and payment callbacks bind the tenant from the URL slug or from the record being paid for.
- `RequireTenant` rejects tenant-scoped routes with no tenant; `BindPrimaryTenant` binds JIT for the back office.

Rules that keep this safe:

- `tenant_id` is not mass-assignable. Use `createForTenant()` / `firstOrCreateForTenant()`.
- A model whose table has no `tenant_id` must not use `TenantAware`.
- The console (scheduled jobs) runs unscoped, so every query there names its tenant explicitly.

## Portals

| Portal | Path | Who |
|---|---|---|
| Student | `/lms/app` | Modules, materials, classroom, timetable, tasks, attendance, certificates, chat, billing (monthly courses) |
| Staff | `/lms/staff` | Course and module authoring, AI materials, students, attendance, tasks, reports, chat |
| Academy owner | `/lms/admin` | Setup wizard, branding, domains, plan billing, payout bank, courses and cohorts, staff, students, agents, payments |
| Admission agents | `/lms/agent` | Referral links, registrations, commissions, withdrawals |
| Public | `/`, `/i/{slug}`, `/campuses`, `/pricing`, `/signup` | Marketing site, per-academy storefronts, academy directory |
| Super-admin | `/{ADMIN_DIR}/lms` | Botble back office: every academy, revenue ledger, global announcements |

Each portal area has its own layout (`layout.tsx`) that supplies the branded shell. Academies can install their portal as a PWA under their own name and logo.

## Plans (academy pays the platform)

Defined in `config/saas.php` → `plans`, read only through `Tenant::planConfig()`, `planLimit()` and `planFeature()`.

| Plan | Price / month | Courses | Students | Staff | Per class |
|---|---|---|---|---|---|
| Free ("Start") | ₦0 | 3 | 1 | 1 | n/a |
| Basic ("Grow") | ₦5,000 | 10 | unlimited | 5 | 30 |
| Pro ("Scale") | ₦15,000 | 50 | unlimited | 25 | 50 |
| Enterprise ("Expand") | contact sales | unlimited | unlimited | unlimited | 50 platform cap |

Feature gates (chat, certificates, pre-recorded video, agents, branding removal, analytics, custom domain, AI materials) are per plan in the same config. Past-due freezing of the owner portal (`EnsureSubscriptionActive`) ships switched off: `SUBSCRIPTION_ENFORCE_FREEZE=false`.

## Student payments (student pays the academy)

- Course fees settle to the **academy's own Paystack subaccount**. A non-primary academy cannot take paid registrations until it links a bank.
- **Service charge: a flat 5% on every plan**, deducted from the academy's share. Sent to Paystack per transaction (`transaction_charge`) and recorded on each payment (`payments.platform_fee` / `academy_amount`). The owner gets the breakdown on every payment.
- **One-time or monthly, per course.** A monthly course is billed every month: card payers are re-charged automatically, everyone else gets a pay link; access pauses 3 days after a missed payment and returns on payment; billing stops when the cohort ends or the student cancels. Logic: `App\Services\CourseBilling`; scheduled job `lms:process-course-renewals` (hourly).
- Admission agents earn a commission (default 5%, set per academy) on the first payment only.
- Display prices are localized by visitor country; charges are NGN (USD only if `USD_CHARGE_ENABLED`).

## Deploying

1. `php artisan migrate`, then `php artisan config:cache`.
2. Every setting is read through `config()`, never `env()` at a call site: after `config:cache`, `env()` returns null. This has caused live outages before (login 404, payments 503).
3. Production flags that must be on: `LMS_FEATURE_ENABLED`, `TRAINING_FEATURE_ENABLED`, `FRONTEND_API_ENABLED`, `TRAINING_EMAIL_ENABLED`.
4. Set `APP_DOMAIN`, `LMS_BASE_URL` (the public frontend URL used in every emailed link) and live Paystack keys. The frontend needs `LARAVEL_BACKEND_URL`.
5. Run the scheduler (`php artisan schedule:run` every minute). It drives notification emails, announcements, attendance, cohort-end notices, subscription reminders, account purges and monthly-course renewals.
6. Point the Paystack webhook at `/api/paystack/webhook`. It handles plan payments and student course payments.

## Not built yet

- Push notifications for the PWA.
- Bring-your-own-domain TLS at scale.
- Per-academy database isolation (not planned; the shared-database design is deliberate).
