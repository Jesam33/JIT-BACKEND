<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;

class SetTenant extends Command
{
    protected $signature = 'tenant:set {slug}';

    protected $description = 'Set the current tenant for the CLI runtime';

    public function handle()
    {
        $slug = $this->argument('slug');
        $tenant = Tenant::where('slug', $slug)->first();
        if (! $tenant) {
            $this->error('Tenant not found: ' . $slug);
            return 1;
        }

        app()->instance('currentTenant', $tenant);
        $this->info('Set current tenant to: ' . $tenant->slug);
        return 0;
    }
}
