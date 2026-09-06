<?php

namespace App\Console\Commands;

use App\Mail\CeoForumMail;
use App\Models\CeoForum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails institute owners about CEO's Forums, in two sweeps that mirror the
 * announcement/notification delivery pattern (mail is synchronous, cron drives
 * it every minute, per-forum stamps make each sweep idempotent + retry-safe):
 *
 *   1. Invite sweep    — forums that are scheduled and have never been sent an
 *                        invite (invite_sent_at IS NULL). One email per owner,
 *                        then stamp invite_sent_at.
 *   2. Reminder sweep  — scheduled forums whose start is within the next hour and
 *                        that have not had a reminder yet. Stamp reminder_sent_at.
 *
 * It also advances forum status (scheduled → live → ended) so the owner page and
 * host list reflect reality without manual edits. Cheap: one owner-roster query
 * shared across every forum in the run.
 */
class SendCeoForumEmails extends Command
{
    protected $signature = 'lms:send-ceo-forum-emails';

    protected $description = 'Email institute owners CEO forum invites + reminders, and advance forum status.';

    /** Reminder window: forums starting within this many minutes get the nudge. */
    private const REMINDER_WINDOW_MINUTES = 60;

    public function handle(): int
    {
        $this->advanceStatuses();

        $owners = $this->ownerRoster();
        if (empty($owners)) {
            $this->info('No institute owners to notify.');

            return self::SUCCESS;
        }

        $sent = 0;
        $sent += $this->inviteSweep($owners);
        $sent += $this->reminderSweep($owners);

        $this->info("CEO forum email sweep complete: {$sent} email(s) sent.");

        return self::SUCCESS;
    }

    /**
     * Flip forums live once their start passes, and ended once they finish, so the
     * owner-facing state stays honest without a human touching each row.
     */
    private function advanceStatuses(): void
    {
        $now = now();

        $active = CeoForum::query()
            ->whereIn('status', [CeoForum::STATUS_SCHEDULED, CeoForum::STATUS_LIVE])
            ->whereNotNull('scheduled_at')
            ->get();

        foreach ($active as $forum) {
            if ($now->greaterThan($forum->endsAt())) {
                if ($forum->status !== CeoForum::STATUS_ENDED) {
                    $forum->forceFill(['status' => CeoForum::STATUS_ENDED])->save();
                }
            } elseif ($now->greaterThanOrEqualTo($forum->scheduled_at) && $forum->status === CeoForum::STATUS_SCHEDULED) {
                $forum->forceFill(['status' => CeoForum::STATUS_LIVE])->save();
            }
        }
    }

    /**
     * Every institute owner as [ { user_id, name, email, tenant_slug } ]. One
     * owner may hold several institutes; we key by user_id + pick a tenant slug
     * so the deep link pins one of their institutes on a cold tap.
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
                'users.id as user_id',
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
            // First institute wins for the pin; a repeat user_id keeps the earlier.
            if (isset($owners[$r->user_id])) {
                continue;
            }
            $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'there';
            $owners[$r->user_id] = [
                'name' => $name,
                'email' => $r->email,
                'slug' => $r->slug,
            ];
        }

        return array_values($owners);
    }

    /**
     * @param array<int,array{name:string,email:string,slug:?string}> $owners
     */
    private function inviteSweep(array $owners): int
    {
        $forums = CeoForum::query()
            ->where('status', CeoForum::STATUS_SCHEDULED)
            ->whereNull('invite_sent_at')
            ->whereNotNull('scheduled_at')
            ->orderBy('id')
            ->get();

        $sent = 0;
        foreach ($forums as $forum) {
            $sent += $this->blast(CeoForumMail::MODE_INVITE, $forum, $owners);
            $forum->forceFill(['invite_sent_at' => now()])->save();
        }

        return $sent;
    }

    /**
     * @param array<int,array{name:string,email:string,slug:?string}> $owners
     */
    private function reminderSweep(array $owners): int
    {
        $now = now();

        $forums = CeoForum::query()
            ->where('status', CeoForum::STATUS_SCHEDULED)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$now, $now->copy()->addMinutes(self::REMINDER_WINDOW_MINUTES)])
            ->orderBy('id')
            ->get();

        $sent = 0;
        foreach ($forums as $forum) {
            $sent += $this->blast(CeoForumMail::MODE_REMINDER, $forum, $owners);
            $forum->forceFill(['reminder_sent_at' => now()])->save();
        }

        return $sent;
    }

    /**
     * Send one forum email to every owner. Failures are logged but never abort the
     * run: the per-forum stamp is set by the caller either way (an invite/reminder
     * is a best-effort single touch, not a retried transactional mail).
     *
     * @param array<int,array{name:string,email:string,slug:?string}> $owners
     */
    private function blast(string $mode, CeoForum $forum, array $owners): int
    {
        $base = rtrim((string) config('saas.frontend_url'), '/');
        $sent = 0;

        foreach ($owners as $owner) {
            $joinUrl = $base . '/lms/admin/forum';
            if (! empty($owner['slug'])) {
                $joinUrl .= '?tenant=' . urlencode($owner['slug']);
            }

            try {
                Mail::to($owner['email'])->send(
                    CeoForumMail::forOwner($mode, $forum, $owner['name'], $joinUrl)
                );
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('lms:send-ceo-forum-emails: send failed', [
                    'forum_id' => $forum->id,
                    'mode' => $mode,
                    'email' => $owner['email'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
