<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone');
            $table->text('home_address');
            $table->string('qualification');
            $table->json('custom_answers');
            $table->string('referral_code')->unique();
            $table->string('status')->default('pending');
            $table->string('password')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('token', 100)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->decimal('course_price', 12, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->string('status')->default('pending');
            $table->string('type')->default('referral');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('training_registrations', function (Blueprint $table) {
            $table->unsignedBigInteger('referred_by_agent_id')->nullable()->after('id');
            $table->unsignedBigInteger('registered_by_agent_id')->nullable()->after('referred_by_agent_id');
        });

        Schema::table('lms_students', function (Blueprint $table) {
            $table->unsignedBigInteger('referred_by_agent_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('lms_students', fn(Blueprint $t) => $t->dropColumn('referred_by_agent_id'));
        Schema::table('training_registrations', fn(Blueprint $t) => $t->dropColumn(['referred_by_agent_id', 'registered_by_agent_id']));
        Schema::dropIfExists('agent_commissions');
        Schema::dropIfExists('agent_notifications');
        Schema::dropIfExists('agent_sessions');
        Schema::dropIfExists('agents');
    }
};
