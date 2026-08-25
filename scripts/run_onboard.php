<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = App\Models\Tenant::find($argv[1] ?? null);
$owner = App\Models\User::find($argv[2] ?? null);
if (! $tenant) {
    echo "Tenant not found\n";
    exit(1);
}
$svc = app()->make(App\Services\TenantOnboardingService::class);
try {
    $res = $svc->run($tenant, $owner);
    print_r($res);
} catch (\Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
