<?php

namespace App\Console\Commands;

use App\Mail\LmsNotificationMail;
use App\Models\Agent;
use App\Models\AgentNotification;
use App\Models\LmsNotification;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTeacherNotification;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\NotificationLinks;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails pending in-app notifications.
 *
 * Because mail is synchronous ({@see config('queue.default')} = sync) and the
 * shared host has no queue worker, notifications are created inline (fast) and
 * this scheduled sweep does the actual sending: it picks rows whose `emailed_at`
 * is still null (and that have not exhausted their retry budget), sends one
 * {@see LmsNotificationMail}, and stamps `emailed_at` on success / bumps
 * `email_attempts` on failure. Runs across ALL tenants (no tenant is bound in
 * the scheduler, and it defensively drops the TenantScope regardless).
 */
class SendNotificationEmails extends Command
{
    protected $signature = 'lms:send-notification-emails';

    protected $description = 'Email pending in-app notifications (emailed_at IS NULL) to students, staff and agents.';

    public function handle(): int
    {
        if (! config('saas.notification_emails_enabled')) {
            $this->info('Notification emails are disabled (saas.notification_emails_enabled=false).');

            return self::SUCCESS;
        }

        $batch = max(1, (int) config('saas.notification_email_batch', 120));
        $maxAttempts = max(1, (int) config('saas.notification_email_max_attempts', 3));
        $exclude = array_values((array) config('saas.notification_email_exclude_types', []));

        $tenantNames = Tenant::query()->pluck('name', 'id')->all();
        $slugs = NotificationLinks::tenantSlugMap();
        $replyTos = $this->replyToMap();

        $sent = 0;
        $sent += $this->sweepStudents($batch, $maxAttempts, $exclude, $tenantNames, $slugs, $replyTos);
        $sent += $this->sweepStaff($batch, $maxAttempts, $exclude, $tenantNames, $slugs, $replyTos);
        $sent += $this->sweepAgents($batch, $maxAttempts, $exclude, $tenantNames, $slugs, $replyTos);

        $this->info("Notification email sweep complete: {$sent} sent.");

        return self::SUCCESS;
    }

    /** @param string[] $exclude @param array<int,string> $tenantNames @param array<int,string> $slugs @param array<int,string> $replyTos */
    private function sweepStudents(int $batch, int $maxAttempts, array $exclude, array $tenantNames, array $slugs, array $replyTos): int
    {
        $notifs = $this->pending(LmsNotification::query(), $batch, $maxAttempts, $exclude);
        if ($notifs->isEmpty()) {
            return 0;
        }

        $recipients = LmsStudent::withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $notifs->pluck('student_id')->unique()->filter()->all())
            ->get()
            ->keyBy('id');

        $sent = 0;
        foreach ($notifs as $n) {
            $r = $recipients->get($n->student_id);
            $name = $r
                ? (trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ($r->username ?: 'there'))
                : 'there';
            $sent += $this->deliver($n, 'student', $r?->email, $name, $tenantNames, $slugs, $replyTos) ? 1 : 0;
        }

        return $sent;
    }

    /** @param string[] $exclude @param array<int,string> $tenantNames @param array<int,string> $slugs @param array<int,string> $replyTos */
    private function sweepStaff(int $batch, int $maxAttempts, array $exclude, array $tenantNames, array $slugs, array $replyTos): int
    {
        $notifs = $this->pending(LmsTeacherNotification::query(), $batch, $maxAttempts, $exclude);
        if ($notifs->isEmpty()) {
            return 0;
        }

        $recipients = LmsTeacher::withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $notifs->pluck('teacher_id')->unique()->filter()->all())
            ->get()
            ->keyBy('id');

        $sent = 0;
        foreach ($notifs as $n) {
            $r = $recipients->get($n->teacher_id);
            $name = $r ? (trim((string) $r->name) ?: ($r->username ?: 'there')) : 'there';
            $sent += $this->deliver($n, 'staff', $r?->email, $name, $tenantNames, $slugs, $replyTos) ? 1 : 0;
        }

        return $sent;
    }

    /** @param string[] $exclude @param array<int,string> $tenantNames @param array<int,string> $slugs @param array<int,string> $replyTos */
    private function sweepAgents(int $batch, int $maxAttempts, array $exclude, array $tenantNames, array $slugs, array $replyTos): int
    {
        $notifs = $this->pending(AgentNotification::query(), $batch, $maxAttempts, $exclude);
        if ($notifs->isEmpty()) {
            return 0;
        }

        $recipients = Agent::withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $notifs->pluck('agent_id')->unique()->filter()->all())
            ->get()
            ->keyBy('id');

        $sent = 0;
        foreach ($notifs as $n) {
            $r = $recipients->get($n->agent_id);
            $name = $r ? (trim((string) $r->name) ?: 'there') : 'there';
            $sent += $this->deliver($n, 'agent', $r?->email, $name, $tenantNames, $slugs, $replyTos) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * [tenant_id => reply-to email] for each institute's outgoing mail.
     *
     * The from-address stays on the platform's verified domain (deliverability),
     * but replies should reach the INSTITUTE, not the platform. Prefer the
     * institute's published public contact email (settings.profile.contact.email
     * — what the owner deliberately exposes on their storefront); fall back to the
     * owner's own login email (tenant_admins.role = owner). Built once per run
     * (two queries) like the tenant name/slug maps — never per-row. Any address
     * that is missing or invalid is simply absent; {@see LmsNotificationMail}
     * re-validates before it sets Reply-To, so a bad value just yields no Reply-To.
     *
     * @return array<int,string>
     */
    private function replyToMap(): array
    {
        // Owner login email — the always-present fallback.
        $owners = DB::table('tenant_admins')
            ->join('users', 'users.id', '=', 'tenant_admins.user_id')
            ->where('tenant_admins.role', 'owner')
            ->pluck('users.email', 'tenant_admins.tenant_id')
            ->all();

        // Published public contact email takes priority when present + valid.
        $contact = [];
        foreach (Tenant::query()->get(['id', 'settings']) as $t) {
            $email = data_get($t->settings, 'profile.contact.email');
            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $contact[(int) $t->id] = $email;
            }
        }

        // contact wins; owner fills the gaps.
        return $contact + $owners;
    }

    /**
     * Pending, un-emailed notifications for one table, oldest first.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string[]  $exclude
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function pending($query, int $batch, int $maxAttempts, array $exclude)
    {
        return $query
            ->withoutGlobalScope(TenantScope::class)
            ->whereNull('emailed_at')
            ->where('email_attempts', '<', $maxAttempts)
            ->when($exclude, fn ($q) => $q->whereNotIn('type', $exclude))
            ->orderBy('id')
            ->limit($batch)
            ->get();
    }

    /**
     * Send one notification email and record the outcome on the row.
     *
     * @param  array<int,string>  $tenantNames
     * @param  array<int,string>  $slugs
     * @param  array<int,string>  $replyTos
     */
    private function deliver(Model $n, string $audience, ?string $email, string $name, array $tenantNames, array $slugs, array $replyTos): bool
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // No deliverable address (recipient deleted, or address invalid).
            // Stamp it so the sweep stops reconsidering it every minute.
            $n->forceFill(['emailed_at' => now()])->saveQuietly();

            return false;
        }

        $tid = $n->tenant_id;
        $institute = $tenantNames[$tid] ?? (string) config('app.name', 'Jorsas Tech');
        $slug = $slugs[$tid] ?? null;
        $replyTo = $replyTos[$tid] ?? null;
        $url = NotificationLinks::forNotification($audience, $n->reference_type, $n->reference_id, $slug);

        try {
            Mail::to($email)->send(new LmsNotificationMail(
                greetingName: $name,
                notifTitle: (string) $n->title,
                notifBody: (string) $n->body,
                actionUrl: $url,
                instituteName: $institute,
                instituteReplyTo: $replyTo,
            ));

            $n->forceFill(['emailed_at' => now()])->saveQuietly();

            return true;
        } catch (\Throwable $e) {
            $n->increment('email_attempts');
            Log::warning('lms:send-notification-emails: send failed', [
                'table' => $n->getTable(),
                'id' => $n->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
