<?php

namespace App\Console\Commands;

use App\Models\QaEvent;
use App\Models\QaTester;
use Illuminate\Console\Command;

/**
 * Clear out a QA testing event once the day is over.
 *
 * Deletes the testers and the rooms but leaves the event row and the academy, so
 * the record of what ran survives and re-running qa:setup-event brings the same
 * event back with a fresh host token if it is ever needed again.
 *
 * `--force` is required for the actual delete, and the row count is printed first,
 * because this is the one irreversible step in the whole feature.
 */
class PurgeQaEvent extends Command
{
    protected $signature = 'qa:purge-event
        {slug : The event to clear, e.g. iungo}
        {--keep-event : Delete the testers and rooms but leave the event row itself}
        {--force : Actually delete. Without this the command only reports what it would remove.}';

    protected $description = 'Delete the testers and video rooms belonging to a finished QA testing event.';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');

        $event = QaEvent::query()->where('slug', $slug)->first();

        if (! $event) {
            $this->error("No QA event with slug '{$slug}'.");

            return self::FAILURE;
        }

        $testers = QaTester::query()->where('qa_event_id', $event->id)->count();
        $slots = $event->slots()->count();

        $this->line("Event '{$event->name}' (#{$event->id}): {$testers} testers, {$slots} rooms.");

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run. Nothing was deleted. Re-run with --force to remove the above.');

            return self::SUCCESS;
        }

        // Testers first: slots are what they point at, and a partial run should
        // leave rooms rather than dangling tester rows.
        $deletedTesters = QaTester::query()->where('qa_event_id', $event->id)->delete();
        $deletedSlots = $event->slots()->delete();

        $this->info("Deleted {$deletedTesters} testers and {$deletedSlots} rooms.");

        if (! $this->option('keep-event')) {
            $event->delete();
            $this->info('Deleted the event row. The academy was left in place.');
        }

        return self::SUCCESS;
    }
}
