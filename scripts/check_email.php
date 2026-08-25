<?php
if ($argc < 2) {
    echo "Usage: php check_email.php email@example.com\n";
    exit(1);
}
$email = $argv[1];
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Foundation\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "Checking for: $email\n";

if (Schema::hasTable('users')) {
    $u = DB::table('users')->where('email', $email)->first();
    echo "users: " . ($u ? 'FOUND (id=' . $u->id . ')' : 'NOT FOUND') . "\n";
} else {
    echo "users table missing\n";
}

if (Schema::hasTable('lms_teachers')) {
    $t = DB::table('lms_teachers')->where('email', $email)->first();
    echo "lms_teachers: " . ($t ? 'FOUND (id=' . $t->id . ')' : 'NOT FOUND') . "\n";
} else {
    echo "lms_teachers table missing\n";
}

if (Schema::hasTable('tenant_admins')) {
    $a = DB::table('tenant_admins')->where('user_id', function($q) use ($email) {
        $q->select('id')->from('users')->where('email', $email)->limit(1);
    })->first();
    echo "tenant_admins link: " . ($a ? 'FOUND' : 'NOT FOUND') . "\n";
} else {
    echo "tenant_admins table missing\n";
}

// check owner_invitations
if (Schema::hasTable('owner_invitations')) {
    $inv = DB::table('owner_invitations')->where('email', $email)->first();
    echo "owner_invitations: " . ($inv ? 'FOUND' : 'NOT FOUND') . "\n";
} else {
    echo "owner_invitations table missing\n";
}

exit(0);
