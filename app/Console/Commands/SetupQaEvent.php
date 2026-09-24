<?php

namespace App\Console\Commands;

use App\Models\QaEvent;
use App\Models\QaSlot;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Stand up a QA testing event in one go: the academy behind it, the event row and
 * its rooms. Idempotent, so running it again after changing the slot list adds the
 * rooms that are missing and touches nothing else.
 *
 * The tenant is created on the PRO plan and, deliberately, with NO billing period
 * (`current_period_end` null, `subscription_status` active) rather than through
 * Tenant::activatePlan(). activatePlan() stamps a period end one month out, which
 * would then let the academy drift into the grace window and on to `frozen` for a
 * subscription nobody ever bought. An event academy is comped for its event and
 * has no period at all; null reads as 'active' in subscriptionState() forever.
 *
 * Usage:
 *   php artisan qa:setup-event iungo \
 *     --name="iungo x Jorsas Tech QA Testing" \
 *     --starts-at="2026-10-05 09:00" --ends-at="2026-10-05 17:00" \
 *     --slot="10:00-11:30" --slot="12:00-13:30" --slot="14:00-15:30"
 */
class SetupQaEvent extends Command
{
    protected $signature = 'qa:setup-event
        {slug : The public URL segment, e.g. iungo (used at /qa/{slug})}
        {--name= : Display name for the event}
        {--blurb= : One or two lines shown on the registration page}
        {--starts-at= : When registration opens, e.g. "2026-10-05 09:00"}
        {--ends-at= : When registration closes and the links die}
        {--slot=* : A room, as "10:00-11:30". Repeatable.}
        {--capacity= : Optional seat cap per room}
        {--tenant-name= : The academy name, if the tenant does not exist yet}';

    protected $description = 'Create or update a QA testing event: its academy, event row and video rooms.';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');

        $tenant = $this->ensureTenant($slug);

        $startsAt = $this->parseDate($this->option('starts-at'), 'starts-at');
        $endsAt = $this->parseDate($this->option('ends-at'), 'ends-at');

        if ($startsAt === false || $endsAt === false) {
            return self::FAILURE;
        }

        $event = QaEvent::query()->firstOrNew(['slug' => $slug]);

        $event->tenant_id = $tenant->id;
        $event->name = (string) ($this->option('name') ?: ($event->name ?: $slug . ' QA Testing'));
        $event->blurb = $this->option('blurb') ?: $event->blurb;
        $event->starts_at = $startsAt ?: $event->starts_at;
        $event->ends_at = $endsAt ?: $event->ends_at;
        // Minted once and never rotated by a re-run: rotating it would invalidate
        // the host link the iungo team has already been given.
        $event->host_token = $event->host_token ?: QaEvent::mintHostToken();
        $event->is_active = true;
        $event->save();

        $this->info("Event '{$event->name}' (#{$event->id}) on academy '{$tenant->name}' (plan {$tenant->planSlug()}).");

        $added = $this->syncSlots($event);

        $this->newLine();
        $this->line('  Registration page: <fg=green>' . $event->registerUrl() . '</>');
        $this->line('  Host link:         <fg=green>' . $event->hostUrl() . '</>');
        $this->newLine();
        $this->line('  Share the host link with the iungo team only. It joins as moderator.');
        $this->line('  Rooms: ' . ($added === 0 ? 'none added (all already present)' : $added . ' added'));

        if (! config('saas.qa_events_enabled')) {
            $this->newLine();
            $this->warn('QA_EVENTS_ENABLED is false, so every QA endpoint is still 404ing.');
            $this->warn('Set QA_EVENTS_ENABLED=true on the server and run `php artisan config:cache`.');
        }

        return self::SUCCESS;
    }

    /**
     * Find the academy or create it, and pin it to Pro with no billing period.
     * Idempotent: re-running resets the plan but never overwrites the name.
     */
    private function ensureTenant(string $slug): Tenant
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant) {
            $tenant = Tenant::create([
                'slug' => $slug,
                'name' => (string) ($this->option('tenant-name') ?: $slug),
                'status' => 'active',
            ]);
            $this->info("Created academy '{$tenant->name}' (#{$tenant->id}).");
        }

        $tenant->forceFill([
            'plan' => 'pro',
            'subscription_status' => 'active',
            // No period: see the class docblock. This is what keeps the academy out
            // of the grace/frozen lifecycle for a subscription it never bought.
            'current_period_end' => null,
        ])->save();

        return $tenant;
    }

    /** Add any rooms that are not there yet, keyed on the label. Returns how many were added. */
    private function syncSlots(QaEvent $event): int
    {
        $labels = array_filter(array_map('trim', (array) $this->option('slot')));

        if (empty($labels)) {
            return 0;
        }

        $capacity = $this->option('capacity') !== null ? (int) $this->option('capacity') : null;
        $added = 0;

        foreach (array_values($labels) as $index => $label) {
            // Match on the normalised label so "10:00-11:30" and "10:00 - 11:30"
            // are the same room rather than two.
            $normalised = $this->normaliseLabel($label);

            $exists = $event->slots()->get()->contains(
                fn (QaSlot $slot) => $this->normaliseLabel($slot->label) === $normalised
            );

            if ($exists) {
                continue;
            }

            [$from, $to] = $this->splitWindow($label);

            $event->slots()->create([
                'label' => $normalised,
                'starts_at' => $this->slotMoment($event, $from),
                'ends_at' => $this->slotMoment($event, $to),
                'capacity' => $capacity,
                'sort_order' => $index,
                'is_active' => true,
            ]);

            $added++;
            $this->line("  + room {$normalised}");
        }

        return $added;
    }

    /** "10:00-11:30" / "10:00 - 11:30" / "10:00–11:30" all become "10:00 - 11:30". */
    private function normaliseLabel(string $label): string
    {
        [$from, $to] = $this->splitWindow($label);

        return $to === null ? trim($label) : $from . ' - ' . $to;
    }

    /** @return array{0: string, 1: ?string} */
    private function splitWindow(string $label): array
    {
        // Accept hyphen, en dash and em dash as the separator: these get pasted in
        // from all sorts of places.
        $parts = preg_split('/\s*[-–—]\s*/u', trim($label), 2);

        return [trim($parts[0] ?? $label), isset($parts[1]) ? trim($parts[1]) : null];
    }

    /**
     * Turn a "HH:MM" into a datetime on the event's own date. Returns null when the
     * event has no date yet, which the slot model reads as "no window", i.e. open
     * whenever the event is open. That is the right default for a one-day event
     * whose dates are not pinned down yet: the rooms work as soon as the event
     * does, and setting the event's date narrows them.
     */
    private function slotMoment(QaEvent $event, ?string $time): ?Carbon
    {
        if ($time === null || $time === '' || ! $event->starts_at) {
            return null;
        }

        $date = $event->starts_at->copy()->startOfDay();

        return Carbon::parse($date->toDateString() . ' ' . $time);
    }

    /** @return Carbon|false|null null when unset, false when unparseable. */
    private function parseDate(?string $value, string $flag): Carbon|false|null
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            $this->error("Could not read --{$flag}=\"{$value}\". Try \"2026-10-05 09:00\".");

            return false;
        }
    }
}
