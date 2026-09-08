<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom domains for Pro/Enterprise academies (learn.theiracademy.com instead
     * of jorsastech.com/?tenant=slug). One tenant may point several hostnames at
     * the platform; each is verified by a DNS TXT record before it is allowed to
     * resolve. ResolveTenant looks the incoming Host up here (verified rows only),
     * so an unverified or foreign host can never bind a tenant.
     *
     * Additive and self-contained: no existing table is touched, so this is
     * zero-downtime and a no-op for every academy that never adds a domain.
     */
    public function up(): void
    {
        if (Schema::hasTable('tenant_domains')) {
            return;
        }

        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // The hostname, stored lowercased without scheme or path
            // (learn.theiracademy.com). Globally unique: a host can only ever
            // point at one academy.
            $table->string('host')->unique();
            // pending until the DNS TXT record is seen, then verified. A row is
            // only honoured by ResolveTenant while verified.
            $table->string('status')->default('pending'); // pending | verified
            // The random token the owner must publish as a TXT record so we can
            // prove they control the domain before it resolves.
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            // Marks the academy's canonical custom host (the one used to build
            // absolute links). The first verified domain becomes primary.
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
