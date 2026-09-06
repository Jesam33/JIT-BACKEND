<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot health check for the email pipeline on a live (cPanel) box.
 *
 * Answers the two questions that a silent cron leaves open:
 *   1. Is SMTP actually able to send from THIS server with the LIVE config?
 *      (`--to=you@example.com` sends one plain test message, synchronously,
 *      surfacing the real SMTP error instead of swallowing it.)
 *   2. Is there a backlog the scheduler should have drained? (counts rows with
 *      `emailed_at IS NULL` across the three notification tables + queued
 *      announcements + un-invited forums.)
 *
 * It also prints the effective mail config the app is really running with,
 * so a stale `config:cache` (env changed but cache not rebuilt) is obvious.
 *
 * Read-only except for the optional --to send. Safe to run on production.
 */
class MailDoctor extends Command
{
    protected $signature = 'lms:mail-doctor {--to= : Send a single test email to this address using the live SMTP config}';

    protected $description = 'Diagnose the email pipeline: show effective mail config, count the unsent backlog, and optionally send one test message.';

    public function handle(): int
    {
        $this->line('');
        $this->info('=== Effective mail config (what the app is really using) ===');
        // Read through config() (post-cache), NOT env(), so a stale config:cache shows up.
        $rows = [
            ['MAIL_MAILER', config('mail.default')],
            ['MAIL_HOST', config('mail.mailers.smtp.host')],
            ['MAIL_PORT', config('mail.mailers.smtp.port')],
            ['MAIL_ENCRYPTION/scheme', config('mail.mailers.smtp.encryption') ?? config('mail.mailers.smtp.scheme')],
            ['MAIL_USERNAME', config('mail.mailers.smtp.username')],
            ['MAIL_PASSWORD', config('mail.mailers.smtp.password') ? '(set, '.strlen((string) config('mail.mailers.smtp.password')).' chars)' : '(EMPTY)'],
            ['MAIL_FROM_ADDRESS', config('mail.from.address')],
            ['MAIL_FROM_NAME', config('mail.from.name')],
            ['APP_ENV', config('app.env')],
            ['APP_URL', config('app.url')],
            ['saas.frontend_url', config('saas.frontend_url')],
            ['saas.notification_emails_enabled', config('saas.notification_emails_enabled') ? 'true' : 'false'],
            ['saas.training_email_enabled', config('saas.training_email_enabled') ? 'true' : 'false'],
        ];
        $this->table(['key', 'value'], $rows);

        $this->line('');
        $this->info('=== Unsent backlog (rows the scheduler should be draining) ===');
        $backlog = [];
        foreach ([
            'lms_notifications' => 'student notifications',
            'lms_teacher_notifications' => 'staff notifications',
            'agent_notifications' => 'agent notifications',
        ] as $table => $label) {
            if (! Schema::hasTable($table)) {
                $backlog[] = [$label, 'table missing'];
                continue;
            }
            $pending = DB::table($table)->whereNull('emailed_at')->count();
            $backlog[] = [$label, $pending.' waiting to be emailed'];
        }
        if (Schema::hasTable('platform_announcements')) {
            $queued = DB::table('platform_announcements')->whereNull('dispatched_at')->count();
            $backlog[] = ['platform announcements', $queued.' queued (not yet fanned out)'];
        }
        if (Schema::hasTable('ceo_forums')) {
            $uninvited = DB::table('ceo_forums')
                ->where('status', 'scheduled')
                ->whereNull('invite_sent_at')
                ->count();
            $backlog[] = ['CEO forums', $uninvited.' scheduled with no invite sent'];
        }
        $this->table(['queue', 'pending'], $backlog);
        $this->line('  A backlog that never shrinks = the cron/scheduler is not running.');
        $this->line('  A backlog of 0 but no inbox delivery = SMTP problem (send a test below).');

        $to = $this->option('to');
        if (! $to) {
            $this->line('');
            $this->comment('No --to given, skipping the live send. To test SMTP now:');
            $this->comment('  php artisan lms:mail-doctor --to=you@example.com');
            return self::SUCCESS;
        }

        $this->line('');
        $this->info("=== Sending one test email to {$to} (synchronous, live SMTP) ===");
        try {
            Mail::raw(
                "This is a Jorsas Tech mail-doctor test.\n\nIf you received this, SMTP works from this server with the live config.\nSent at ".now()->toDateTimeString().' UTC.',
                function ($m) use ($to) {
                    $m->to($to)->subject('Jorsas Tech mail-doctor test');
                }
            );
            $this->info('  Sent with no exception. Check the inbox (and spam) for the address above.');
            $this->line('  If it does not arrive, the transport accepted it but the provider dropped it');
            $this->line('  (Gmail port/app-password, DKIM/SPF, or from-address not matching the account).');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('  SMTP send FAILED: '.$e->getMessage());
            $this->line('  This is the real error the cron sweep hits silently. Fix this and mail flows.');
            return self::FAILURE;
        }
    }
}
