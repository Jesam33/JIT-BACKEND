<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// ─── Notification / announcement delivery ──────────────────────────────
// Mail is synchronous and the shared host has no queue worker, so notifications
// are created inline and swept to email by cron. Point the host's cron at
// `php artisan schedule:run` (every minute) to drive both:
//   1. fan queued platform announcements out into per-recipient notifications, then
//   2. email every notification whose `emailed_at` is still null.
// Order matters within the minute so a fresh announcement can be emailed the
// same tick. withoutOverlapping() guards against a slow run stacking on itself.
Schedule::command('lms:dispatch-announcements')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('lms:send-notification-emails')
    ->everyMinute()
    ->withoutOverlapping();

// Attendance safety net: close out join rows for ended classes where the
// student's browser never posted the leave close-out (tab close, crash, app
// switch). NOTE this used to live only in app/Console/Kernel.php, which is
// DEAD in this app — the Laravel 11+ bootstrap registers schedules from this
// file, so the Kernel's schedule() never ran.
Schedule::command('lms:calculate-attendance')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// CEO's Forum: invite owners when a forum is scheduled, remind ~1h before start,
// and advance forum status (scheduled → live → ended). Per-forum stamps keep each
// sweep idempotent, so running every minute never double-sends.
Schedule::command('lms:send-ceo-forum-emails')
    ->everyMinute()
    ->withoutOverlapping();

// Ended-cohort certificates, step 1: when a cohort's end_date passes, email the
// owner once (per-cohort ended_notified_at stamp keeps it idempotent). The
// cohort then waits in the owner bell + Certificates page panel until the owner
// accepts and the certificates are issued. Hourly is plenty — the panel is the
// durable record, this email is the nudge.
Schedule::command('lms:notify-ended-cohorts')
    ->hourly()
    ->withoutOverlapping();

// Subscription reinstatement: every day of a lapsed owner's grace window
// (config('saas.subscription_grace_days'), default 7) email them to reinstate,
// from the day the paid period ends until the portal freezes. Daily, not
// per-minute — it is one reminder per tenant per calendar day, and the tenant's
// `subscription_reminder_on` stamp keeps a re-run from double-sending.
Schedule::command('lms:send-subscription-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// Account lifecycle: the other end of the "delete" button. A deletion is
// cancellable for saas.purge_window_days (30 by default), so the purge cannot
// happen when the button is pressed — it happens here, once a day, for whatever
// window has actually closed. Daily is deliberate: nothing is time-critical at
// this granularity, and a slower cadence is a longer last chance to cancel.
// withoutOverlapping() because a purge that refuses (a staffer still leading a
// cohort) is retried by the next run and must never stack.
Schedule::command('lms:purge-scheduled-accounts')
    ->dailyAt('03:30')
    ->withoutOverlapping();
