<?php

namespace App\Console\Commands;

use App\Mail\CohortEndedMail;
use App\Models\LmsTrack;
use App\Support\CohortCompletion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notifies institute owners when a cohort reaches its end date — step 1 of the
 * ended-cohort certificate flow:
 *
 *   cohort end_date passes → this sweep emails the owner once (the per-cohort
 *   `ended_notified_at` stamp makes re-runs a no-op) → the ended cohort shows
 *   in the owner bell + the Certificates page panel → the owner accepts and
 *   the certificates are issued (see OwnerAdminController).
 *
 * Runs hourly via schedule:run. Console context is unscoped by TenantScope, so
 * the sweep crosses every tenant deliberately; every query below is explicit
 * about tenant_id. Mail is best-effort single-touch (same policy as the CEO
 * forum sweep): the stamp is set even when the send fails, a bounced cohort
 * email is not worth retrying every hour forever — the panel and bell still
 * surface the cohort in-app.
 */
class NotifyEndedCohorts extends Command
{
    protected $signature = 'lms:notify-ended-cohorts';

    protected $description = 'Email institute owners whose cohorts have reached their end date.';

    public function handle(): int
    {
        $cutoff = now();

        $tracks = LmsTrack::query()
            ->whereNotNull('end_date')
            ->whereNull('ended_notified_at')
            ->where('end_date', '<', $cutoff->toDateString())
            ->orderBy('id')
            ->get();

        if ($tracks->isEmpty()) {
            return self::SUCCESS;
        }

        // One owner-roster query for the whole run: tenant_id => [name, email].
        $owners = $this->ownerRoster();

        $sent = 0;
        foreach ($tracks as $track) {
            $owner = $owners[$track->tenant_id] ?? null;

            if ($owner) {
                $sent += $this->notify($track, $owner);
            }

            $track->forceFill(['ended_notified_at' => now()])->save();
        }

        $this->info("Ended-cohort sweep complete: {$sent} email(s) sent, {$tracks->count()} cohort(s) marked.");

        return self::SUCCESS;
    }

    /**
     * Every tenant's owner as tenant_id => [name, email, slug]. Owners without
     * a valid address are dropped (nothing to email — the in-app panel still
     * shows them the cohort).
     *
     * @return array<int,array{name:string,email:string,slug:?string}>
     */
    private function ownerRoster(): array
    {
        $rows = DB::table('tenant_admins')
            ->join('users', 'users.id', '=', 'tenant_admins.user_id')
            ->leftJoin('tenants', 'tenants.id', '=', 'tenant_admins.tenant_id')
            ->where('tenant_admins.role', 'owner')
            ->get([
                'tenant_admins.tenant_id as tenant_id',
                'users.email',
                'users.first_name',
                'users.last_name',
                'tenants.slug as slug',
            ]);

        $owners = [];
        foreach ($rows as $r) {
            if (! $r->email || ! filter_var($r->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            // First owner row per tenant wins; a shared institute has one anyway.
            if (isset($owners[$r->tenant_id])) {
                continue;
            }
            $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'there';
            $owners[$r->tenant_id] = ['name' => $name, 'email' => $r->email, 'slug' => $r->slug];
        }

        return $owners;
    }

    /**
     * @param array{name:string,email:string,slug:?string} $owner
     */
    private function notify(LmsTrack $track, array $owner): int
    {
        $completion = CohortCompletion::forTrack($track);
        $completed = collect($completion['per_student'])
            ->filter(fn ($n) => CohortCompletion::isCompleted($completion['modules_total'], $n))
            ->count();
        $studentCount = $track->enrollments()->distinct('student_id')->count('student_id');

        $base = rtrim((string) config('saas.frontend_url'), '/');
        $reviewUrl = $base . '/lms/admin/certificates';
        if (! empty($owner['slug'])) {
            $reviewUrl .= '?tenant=' . urlencode($owner['slug']);
        }

        $datesLine = null;
        if ($track->start_date || $track->end_date) {
            $from = $track->start_date?->format('M j, Y');
            $to = $track->end_date?->format('M j, Y');
            $datesLine = trim(($from ?? '?') . ' – ' . ($to ?? '?'), ' –?');
        }

        try {
            Mail::to($owner['email'])->send(new CohortEndedMail(
                ownerName: $owner['name'],
                cohortName: $track->name,
                courseTitle: $track->course?->title,
                datesLine: $datesLine,
                completedCount: $completed,
                studentCount: $studentCount,
                reviewUrl: $reviewUrl,
            ));

            return 1;
        } catch (\Throwable $e) {
            Log::warning('lms:notify-ended-cohorts: send failed', [
                'track_id' => $track->id,
                'tenant_id' => $track->tenant_id,
                'email' => $owner['email'],
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
