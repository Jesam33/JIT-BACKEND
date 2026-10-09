<?php

namespace App\Console\Commands;

use App\Services\OneOnOneBooking;
use Illuminate\Console\Command;

/**
 * "Starting soon" reminders for one-on-one sessions, to the student and the
 * teacher, config('saas.one_on_one_booking.reminder_minutes') before the start.
 * Each session is stamped (reminder_sent_at), so re-runs never double-send.
 */
class SendOneOnOneReminders extends Command
{
    protected $signature = 'lms:send-one-on-one-reminders';

    protected $description = 'Remind students and teachers shortly before one-on-one sessions';

    public function handle(): int
    {
        $sent = OneOnOneBooking::sendReminders();
        $this->info("Sent {$sent} one-on-one reminder(s).");

        return self::SUCCESS;
    }
}
