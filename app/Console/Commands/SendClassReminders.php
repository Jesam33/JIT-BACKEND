<?php

namespace App\Console\Commands;

use App\Services\ClassReminders;
use Illuminate\Console\Command;

/** "Your class starts in 20 / 5 minutes" for group classes (see App\Services\ClassReminders). */
class SendClassReminders extends Command
{
    protected $signature = 'lms:send-class-reminders';

    protected $description = 'Remind students 20 and 5 minutes before their group classes start';

    public function handle(): int
    {
        $sent = ClassReminders::run();
        $this->info("Sent {$sent} class reminder(s).");

        return self::SUCCESS;
    }
}
