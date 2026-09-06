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

// CEO's Forum: invite owners when a forum is scheduled, remind ~1h before start,
// and advance forum status (scheduled → live → ended). Per-forum stamps keep each
// sweep idempotent, so running every minute never double-sends.
Schedule::command('lms:send-ceo-forum-emails')
    ->everyMinute()
    ->withoutOverlapping();
