<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paystack_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event')->index();
            $table->string('event_key')->index();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['event', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_events');
    }
};
