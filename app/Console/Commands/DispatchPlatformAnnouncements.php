<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\PlatformAnnouncement;
use App\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fans out queued platform (host) announcements into per-recipient notification
 * rows across EVERY tenant, so the jorsastech host can message all students,
 * staff and agents on all institutes at once.
 *
 * Recipients are chunked and bulk-inserted with an explicit `tenant_id`
 * (bypassing the TenantScope + the TenantAware creating hook), which is both
 * fast and correct for a cross-tenant write. The whole fan-out for one
 * announcement runs in a transaction so a crash mid-way cannot leave a partial
 * broadcast that a re-run would duplicate. Once inserted, those rows are emailed
 * by {@see SendNotificationEmails} like any other notification.
 */
class DispatchPlatformAnnouncements extends Command
{
    protected $signature = 'lms:dispatch-announcements';

    protected $description = 'Fan out queued platform announcements into per-recipient notifications across all tenants.';

    /** Recipient chunk size for the bulk insert. */
    private const CHUNK = 500;

    public function handle(): int
    {
        $queued = PlatformAnnouncement::query()
            ->where('status', PlatformAnnouncement::STATUS_QUEUED)
            ->orderBy('id')
            ->get();

        if ($queued->isEmpty()) {
            $this->info('No queued platform announcements.');

            return self::SUCCESS;
        }

        foreach ($queued as $announcement) {
            $this->dispatchOne($announcement);
        }

        return self::SUCCESS;
    }

    private function dispatchOne(PlatformAnnouncement $a): void
    {
        $audiences = array_values(array_intersect(
            (array) $a->audiences,
            PlatformAnnouncement::AUDIENCES
        ));

        if (empty($audiences)) {
            $a->forceFill([
                'status' => PlatformAnnouncement::STATUS_FAILED,
                'error' => 'No valid audiences selected.',
            ])->save();

            return;
        }

        try {
            $total = DB::transaction(function () use ($a, $audiences) {
                $now = now();
                $count = 0;

                if (in_array('student', $audiences, true)) {
                    $count += $this->fanOut('lms_notifications', 'student_id', LmsStudent::class, $a, $now);
                }
                if (in_array('staff', $audiences, true)) {
                    $count += $this->fanOut('lms_teacher_notifications', 'teacher_id', LmsTeacher::class, $a, $now);
                }
                if (in_array('agent', $audiences, true)) {
                    $count += $this->fanOut('agent_notifications', 'agent_id', Agent::class, $a, $now);
                }

                $a->forceFill([
                    'status' => PlatformAnnouncement::STATUS_DISPATCHED,
                    'recipients_count' => $count,
                    'dispatched_at' => now(),
                ])->save();

                return $count;
            });

            $this->info("Announcement #{$a->id} dispatched to {$total} recipient(s).");
        } catch (\Throwable $e) {
            $a->forceFill([
                'status' => PlatformAnnouncement::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            Log::error('lms:dispatch-announcements: dispatch failed', [
                'announcement_id' => $a->id,
                'error' => $e->getMessage(),
            ]);

            $this->error("Announcement #{$a->id} failed: {$e->getMessage()}");
        }
    }

    /**
     * Insert one notification row per recipient of the given kind, across all
     * tenants, and return how many rows were written.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $recipientModel
     */
    private function fanOut(string $table, string $fkColumn, string $recipientModel, PlatformAnnouncement $a, \DateTimeInterface $now): int
    {
        $count = 0;

        $recipientModel::withoutGlobalScope(TenantScope::class)
            ->select(['id', 'tenant_id'])
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($recipients) use ($table, $fkColumn, $a, $now, &$count) {
                $rows = [];
                foreach ($recipients as $r) {
                    $rows[] = [
                        $fkColumn => $r->id,
                        'tenant_id' => $r->tenant_id,
                        'type' => 'platform_announcement',
                        'title' => $a->title,
                        'body' => $a->body,
                        'reference_type' => 'platform_announcement',
                        'reference_id' => $a->id,
                        'is_read' => false,
                        'email_attempts' => 0,
                        'emailed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows) {
                    DB::table($table)->insert($rows);
                    $count += count($rows);
                }
            });

        return $count;
    }
}
