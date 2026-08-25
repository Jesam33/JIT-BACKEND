<?php
if ($argc < 2) {
    echo "Usage: php delete_user_by_email.php email@example.com\n";
    exit(1);
}
$email = $argv[1];

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Foundation\Console\Kernel::class);
$kernel->bootstrap();

/** @var \Illuminate\Contracts\Console\Kernel $kernel */

try {
    $user = \App\Models\User::where('email', $email)->first();
    if (! $user) {
        echo "NOT_FOUND\n";
        exit(0);
    }

    $userId = $user->id;

    // Remove tenant_admins links for this user
    \Illuminate\Support\Facades\DB::table('tenant_admins')->where('user_id', $userId)->delete();
    // Remove any owner invitations tied to this email (if the table exists)
    if (\Illuminate\Support\Facades\Schema::hasTable('owner_invitations')) {
        \Illuminate\Support\Facades\DB::table('owner_invitations')->where('email', $email)->delete();
    }
    // Remove any teacher records with this email (if table exists)
    if (\Illuminate\Support\Facades\Schema::hasTable('lms_teachers')) {
        \Illuminate\Support\Facades\DB::table('lms_teachers')->where('email', $email)->delete();
    }

    // Delete user
    $user->delete();

    echo "DELETED\n";
    exit(0);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(2);
}
