<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TenantOnboardingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Tenant $tenant;
    public ?User $owner;

    public function __construct(Tenant $tenant, ?User $owner = null)
    {
        $this->tenant = $tenant;
        $this->owner = $owner;
    }

    public function handle(TenantOnboardingService $service): void
    {
        $service->run($this->tenant, $this->owner);
    }
}
