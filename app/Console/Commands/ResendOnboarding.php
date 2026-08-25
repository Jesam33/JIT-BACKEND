<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OnboardingCompleted;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResendOnboarding extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resend:onboarding {tenant_id : ID of the tenant} {--email= : Override recipient email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resend the onboarding email for a tenant without running provisioning or inserts';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant_id');
        $overrideEmail = $this->option('email');

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error('Tenant not found: ' . $tenantId);
            return 1;
        }

        $owner = null;
        if ($overrideEmail) {
            $owner = User::where('email', $overrideEmail)->first();
            if (! $owner) {
                $this->error('No user found with email: ' . $overrideEmail);
                return 2;
            }
        } else {
            $row = DB::table('tenant_admins')->where('tenant_id', $tenant->id)->first();
            if ($row && isset($row->user_id)) {
                $owner = User::find($row->user_id);
            }
            if (! $owner) {
                $this->error('No tenant owner found in tenant_admins. Use --email to specify recipient.');
                return 3;
            }
        }

        try {
            $owner->notify(new OnboardingCompleted($tenant));
            $this->info('Onboarding notification sent to ' . ($owner->email ?? 'unknown'));
            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to send notification: ' . $e->getMessage());
            return 4;
        }
    }
}
