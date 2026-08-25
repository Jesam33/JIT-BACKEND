<?php
// Lightweight queue runner that calls `php artisan queue:work --once` in a loop.
// This avoids long-running worker daemon timeouts on Windows/dev environments.
set_time_limit(0);
$sleep = 3;
foreach ($argv as $i => $arg) {
    if (strpos($arg, '--sleep=') === 0) {
        $sleep = (int) substr($arg, 8) ?: $sleep;
    }
}
echo "Starting queue-runner (sleep={$sleep}s)\n";
while (true) {
    passthru(PHP_BINARY . " artisan queue:work --once --tries=3", $exitCode);
    if ($exitCode !== 0) {
        echo "artisan exited with {$exitCode}\n";
    }
    sleep((int) $sleep);
}
