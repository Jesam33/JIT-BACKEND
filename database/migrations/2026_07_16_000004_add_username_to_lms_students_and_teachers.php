<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lms_students', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique()->after('last_name');
        });

        Schema::table('lms_teachers', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique()->after('name');
        });

        $used = [];

        foreach (DB::table('lms_students')->whereNull('username')->cursor() as $s) {
            $cleanFirst = preg_replace('/[^a-z0-9]/i', '', $s->first_name);
            $cleanLast = preg_replace('/[^a-z0-9]/i', '', $s->last_name);
            if ($cleanFirst) {
                $base = strtolower($cleanFirst . '.' . $cleanLast);
            } else {
                $base = strtolower($cleanLast ?: 'user');
            }
            $u = $base;
            $n = 1;
            while (isset($used[$u])) { $u = $base . $n; $n++; }
            DB::table('lms_students')->where('id', $s->id)->update(['username' => $u]);
            $used[$u] = true;
        }

        foreach (DB::table('lms_teachers')->whereNull('username')->cursor() as $t) {
            $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $t->name) ?: 'user');
            $u = $base;
            $n = 1;
            while (isset($used[$u])) { $u = $base . $n; $n++; }
            DB::table('lms_teachers')->where('id', $t->id)->update(['username' => $u]);
            $used[$u] = true;
        }
    }

    public function down(): void
    {
        Schema::table('lms_students', function (Blueprint $table): void {
            $table->dropColumn('username');
        });

        Schema::table('lms_teachers', function (Blueprint $table): void {
            $table->dropColumn('username');
        });
    }
};
