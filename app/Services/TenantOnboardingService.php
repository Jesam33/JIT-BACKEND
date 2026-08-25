<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\LmsCourse;
use App\Models\LmsModule;
use App\Models\LmsModuleContent;
use App\Models\LmsTrack;
use App\Models\LmsTeacher;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Notifications\OnboardingCompleted;

class TenantOnboardingService
{
    /**
     * Seed default LMS content for a tenant.
     * Returns created resource IDs.
     */
    public function run(Tenant $tenant, ?User $owner = null, bool $notify = true): array
    {
        // Bind current tenant so TenantAware models set tenant_id automatically
        app()->instance('currentTenant', $tenant);

        $courseTitle = 'Getting Started with ' . $tenant->name;
        // reuse if already created for this tenant
        $existing = LmsCourse::where('title', $courseTitle)->first();
        if ($existing) {
            $course = $existing;
        } else {
            $baseSlug = \Illuminate\Support\Str::slug($courseTitle) . '-' . $tenant->id;
            $slug = $baseSlug;
            $attempts = 0;
            while (true) {
                try {
                    $course = LmsCourse::create([
                        'title' => $courseTitle,
                        'slug' => $slug,
                        'description' => 'An introductory course to help your students get started.',
                        'requirements' => 'None',
                        'price' => 0.00,
                        'max_students' => 0,
                        'registered_count' => 0,
                        'is_live_available' => false,
                        'is_prerecorded_available' => true,
                        'is_active' => true,
                    ]);
                    break;
                } catch (\Illuminate\Database\QueryException $e) {
                    $attempts++;
                    if ($attempts > 5) {
                        throw $e;
                    }
                    $slug = $baseSlug . '-' . \Illuminate\Support\Str::random(4);
                }
            }
        }

        // All seed rows below are created idempotently (firstOrCreate on a natural
        // key). Provisioning can be safely retried — e.g. after a transient failure
        // that reverted the tenant to `pending` — without piling up duplicates.
        $module = LmsModule::firstOrCreate(
            ['course_id' => $course->id, 'title' => 'Introduction'],
            [
                'description' => 'Overview and course objectives',
                'objectives' => 'Understand course layout',
                'sort_order' => 1,
                'status' => 'published',
            ]
        );

        $content = LmsModuleContent::firstOrCreate(
            ['module_id' => $module->id, 'title' => 'Welcome'],
            [
                // use enum-allowed type
                'type' => 'text',
                'content_body' => '<p>Welcome to your new course. Edit this content to customize your onboarding.</p>',
                'sort_order' => 1,
            ]
        );

        $teacherId = null;
        if ($owner) {
            // lms_teachers carries tenant_id and is now unique per (tenant, email),
            // so the owner can be a lecturer here even when the same email already
            // teaches at another institute they own. firstOrCreate (auto-scoped to
            // the bound tenant) also lets a re-run reuse the existing row instead of
            // colliding on a second insert.
            $teacher = LmsTeacher::firstOrCreate(
                ['email' => $owner->email],
                [
                    'name' => $owner->name,
                    'username' => $owner->username ?? $owner->email,
                    'is_active' => true,
                    'password' => Hash::make(\Illuminate\Support\Str::random(12)),
                ]
            );
            $teacherId = $teacher->id;
        }

        // Default track keyed on (course, name). A batch is minted only when the
        // track doesn't yet exist, so retries never leave orphan duplicate batches
        // (Batch is not tenant-scoped, so it can't be keyed on the tenant here).
        $track = LmsTrack::where('course_id', $course->id)->where('name', 'Default Track')->first();
        if (! $track) {
            $batch = Batch::create([
                'name' => 'Default Batch',
                'registration_starts_at' => now()->subDays(7),
                'registration_ends_at' => now()->addYear(),
            ]);
            $track = LmsTrack::create([
                'name' => 'Default Track',
                'instructor_id' => $teacherId,
                'batch_id' => $batch->id,
                'course_id' => $course->id,
            ]);
        } elseif ($teacherId && ! $track->instructor_id) {
            // A partial earlier run created the track without an instructor; wire it.
            $track->update(['instructor_id' => $teacherId]);
        }

        $results = [
            'batch_id' => $track->batch_id,
            'course_id' => $course->id,
            'module_id' => $module->id,
            'content_id' => $content->id,
            'track_id' => $track->id,
        ];

        // Record an audit row
        try {
            Log::info('TenantOnboarding: inserting audit', ['tenant_id' => $tenant->id, 'owner_id' => $owner ? $owner->id : null]);
            DB::table('tenant_onboarding_audits')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $owner ? $owner->id : null,
                'payload' => json_encode($results),
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Log::info('TenantOnboarding: inserted audit');
        } catch (\Throwable $e) {
            Log::warning('Failed to write onboarding audit', ['err' => $e->getMessage()]);
        }

        // Notify the owner their institute is ready — but ONLY when the caller
        // asks for it. The pay-first signup flow passes $notify=false: at
        // provisioning time the owner has no password/session yet, so a
        // "Go to dashboard" link would be dead. That flow sends OnboardingCompleted
        // later — from OwnerAuthController::setup(), once the password is set.
        try {
            if ($owner && $notify) {
                $owner->notify(new OnboardingCompleted($tenant));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send onboarding notification', ['err' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Activate a tenant whose signup payment has just cleared, then provision it.
     *
     * Pay-first invariant: a tenant is created `pending` at signup and nothing is
     * provisioned until money confirms. Both confirmation paths — the synchronous
     * browser verify and the asynchronous Paystack webhook — call this, possibly
     * concurrently. The `pending -> active` transition is a single atomic UPDATE,
     * so exactly one caller sees affected=1 and runs the (non-idempotent) invite +
     * onboarding; the loser is a no-op. Safe to call repeatedly.
     */
    public function activatePaidSignup(Tenant $tenant): array
    {
        $isFirst = DB::table('tenants')
            ->where('id', $tenant->id)
            ->where('status', 'pending')
            ->update(['status' => 'active']) === 1;

        $tenant->refresh();

        // Plan chosen at signup lives in settings until activation makes it real.
        $plan = data_get($tenant->settings, 'plan', 'free');
        if ($plan && isset(config('saas.plans', [])[$plan])) {
            $tenant->activatePlan($plan); // idempotent — safe on redelivery
        }

        if (! $isFirst) {
            // A prior confirmation already provisioned this tenant. Do not
            // re-seed content or re-send the one-time owner invitation.
            return ['first_activation' => false, 'onboarding' => null];
        }

        // Resolve the owner user recorded at signup (tenant_admins.role = owner).
        $owner = null;
        $ownerId = DB::table('tenant_admins')
            ->where('tenant_id', $tenant->id)
            ->where('role', 'owner')
            ->value('user_id');
        if ($ownerId) {
            $owner = User::find($ownerId);
        }

        // Seed default LMS content (binds currentTenant internally).
        //
        // If seeding throws, we must NOT leave the tenant sitting `active` with an
        // empty LMS: that state is invisible to the atomic guard above (it only
        // fires on `pending`), so every retry would short-circuit as "already
        // activated" and the institute would never get provisioned. Revert to
        // `pending` and rethrow so the next verify()/webhook call genuinely retries.
        try {
            // Suppress the "institute is ready" email here — the owner still has
            // no password/session, so its dashboard link would be dead. It is sent
            // from OwnerAuthController::setup() after the owner sets a password,
            // which also guarantees it arrives AFTER the setup invitation below.
            $results = $this->run($tenant, $owner, notify: false);
        } catch (\Throwable $e) {
            DB::table('tenants')
                ->where('id', $tenant->id)
                ->where('status', 'active')
                ->update(['status' => 'pending']);
            Log::error('Paid-signup provisioning failed; reverted tenant to pending for retry', [
                'tenant_id' => $tenant->id,
                'err' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Now that payment cleared, issue the single-use owner setup invitation
        // and email it. This is the owner's entry point into their new LMS.
        try {
            if ($owner) {
                $invitation = \App\Models\OwnerInvitation::create([
                    'tenant_id' => $tenant->id,
                    'email' => $owner->email,
                    'token' => \Illuminate\Support\Str::random(80),
                    'expires_at' => now()->addDays(7),
                ]);
                $owner->notify(new \App\Notifications\OwnerSetupInvitation($invitation, $tenant));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed issuing owner setup invitation after paid signup', ['err' => $e->getMessage()]);
        }

        return ['first_activation' => true, 'onboarding' => $results];
    }
}
