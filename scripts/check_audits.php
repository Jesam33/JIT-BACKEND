<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$tenantId = $argv[1] ?? null;
if (!$tenantId) {
    echo "Usage: php scripts/check_audits.php <tenant_id>\n";
    exit(1);
}
$rows = \DB::table('tenant_onboarding_audits')->where('tenant_id', $tenantId)->get();
print_r($rows->toArray());
