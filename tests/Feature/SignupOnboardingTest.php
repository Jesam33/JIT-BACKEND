<?php

namespace Tests\Feature;

use App\Models\LmsTeacher;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OwnerSetupInvitation;
use App\Scopes\TenantScope;
use App\Services\PaystackService;
use App\Services\TenantOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pay-first institute signup. Signup only creates a `pending` tenant + owner and
 * returns a Paystack authorization_url — NOTHING is provisioned and NO setup link
 * is emailed until payment confirms at /api/signup/verify (or via the webhook).
 * PaystackService is faked; no network calls are made.
 */
class SignupOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Academy',
            'slug' => 'test-academy',
            'admin_name' => 'Test Admin',
            'admin_email' => 'testadmin@example.test',
            // No password at signup — the owner sets it later via the emailed
            // setup link. Signup must succeed without one.
            'plan' => 'basic',
        ], $overrides);
    }

    public function test_signup_creates_pending_tenant_and_returns_authorization_url(): void
    {
        Notification::fake();

        $this->mock(PaystackService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->once()->andReturn([
                'status' => true,
                'data' => ['authorization_url' => 'https://paystack.test/pay/abc123'],
            ]);
        });

        $resp = $this->postJson('/api/signup', $this->payload());

        $resp->assertStatus(201)
            ->assertJsonStructure(['authorization_url', 'reference', 'tenant'])
            ->assertJsonPath('authorization_url', 'https://paystack.test/pay/abc123')
            ->assertJsonPath('tenant.status', 'pending');

        // Tenant exists but is pending, and its payment reference was stored.
        $this->assertDatabaseHas('tenants', ['slug' => 'test-academy', 'status' => 'pending']);
        $tenant = Tenant::where('slug', 'test-academy')->first();
        $this->assertNotNull($tenant->paystack_init_reference);

        // Nothing provisioned, no invite emailed — payment hasn't confirmed.
        $this->assertDatabaseMissing('tenant_onboarding_audits', ['tenant_id' => $tenant->id]);
        Notification::assertNothingSent();
    }

    public function test_verify_activates_and_provisions_after_payment(): void
    {
        Notification::fake();

        $this->mock(PaystackService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->once()->andReturn([
                'status' => true,
                'data' => ['authorization_url' => 'https://paystack.test/pay/abc123'],
            ]);
            $mock->shouldReceive('verifyTransaction')->once()->andReturn([
                'status' => true,
                'data' => ['status' => 'success'],
            ]);
        });

        $this->postJson('/api/signup', $this->payload())->assertStatus(201);

        $tenant = Tenant::where('slug', 'test-academy')->first();
        $reference = $tenant->paystack_init_reference;

        $resp = $this->getJson('/api/signup/verify?reference=' . urlencode($reference));

        $resp->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tenant.slug', 'test-academy');

        // Tenant is now active on the paid plan it signed up for.
        $tenant->refresh();
        $this->assertSame('active', $tenant->status);
        $this->assertSame('basic', $tenant->plan);
        $this->assertSame('active', $tenant->subscription_status);
        $this->assertNotNull($tenant->current_period_end);

        // Onboarding ran and the owner setup invitation was emailed.
        $this->assertDatabaseHas('tenant_onboarding_audits', ['tenant_id' => $tenant->id]);
        $owner = User::where('email', 'testadmin@example.test')->first();
        Notification::assertSentTo($owner, OwnerSetupInvitation::class);
    }

    public function test_verify_is_idempotent_across_repeat_calls(): void
    {
        Notification::fake();

        $this->mock(PaystackService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->andReturn([
                'status' => true,
                'data' => ['authorization_url' => 'https://paystack.test/pay/abc123'],
            ]);
            $mock->shouldReceive('verifyTransaction')->andReturn([
                'status' => true,
                'data' => ['status' => 'success'],
            ]);
        });

        $this->postJson('/api/signup', $this->payload())->assertStatus(201);
        $tenant = Tenant::where('slug', 'test-academy')->first();
        $reference = $tenant->paystack_init_reference;

        // Hit verify twice (browser refresh / webhook race). Provisioning must
        // happen exactly once — no duplicate audit rows, no second invite.
        $this->getJson('/api/signup/verify?reference=' . urlencode($reference))->assertStatus(200);
        $this->getJson('/api/signup/verify?reference=' . urlencode($reference))->assertStatus(200);

        $this->assertDatabaseCount('tenant_onboarding_audits', 1);
        $owner = User::where('email', 'testadmin@example.test')->first();
        Notification::assertSentToTimes($owner, OwnerSetupInvitation::class, 1);
    }

    public function test_free_plan_is_rejected_at_signup(): void
    {
        $this->mock(PaystackService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->never();
        });

        $resp = $this->postJson('/api/signup', $this->payload(['plan' => 'free']));

        $resp->assertStatus(422)->assertJsonValidationErrors(['plan']);
        $this->assertDatabaseMissing('tenants', ['slug' => 'test-academy']);
    }

    public function test_signup_requires_configured_gateway(): void
    {
        // No mock: the real PaystackService is unconfigured in the test env
        // (no PAYSTACK_SECRET_KEY), so a valid signup degrades to 503 and never
        // creates an unpayable pending tenant.
        $resp = $this->postJson('/api/signup', $this->payload());

        $resp->assertStatus(503);
        $this->assertDatabaseMissing('tenants', ['slug' => 'test-academy']);
    }

    public function test_same_owner_can_run_multiple_institutes(): void
    {
        Notification::fake();

        // One person owns two institutes — the real SaaS case. Provisioning seeds
        // the owner as a lecturer in each. Under the OLD global unique on
        // lms_teachers.email this collided on the second institute: activation
        // 500'd and left the tenant stuck `active` but empty (the exact
        // /api/signup/verify failure). Per-tenant uniqueness must allow it.
        $owner = User::create([
            'first_name' => 'Sam',
            'last_name' => 'Owner',
            'username' => 'multi-owner@example.test',
            'email' => 'multi-owner@example.test',
            'password' => Hash::make('placeholder'),
        ]);

        $svc = app(TenantOnboardingService::class);
        $tenantIds = [];

        foreach (['alpha-inst', 'beta-inst'] as $slug) {
            $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'pending']);
            DB::table('tenant_admins')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'role' => 'owner',
            ]);

            // The activation that used to throw on the second institute.
            $result = $svc->activatePaidSignup($tenant);

            $this->assertTrue($result['first_activation']);
            $tenant->refresh();
            $this->assertSame('active', $tenant->status);
            $tenantIds[] = $tenant->id;
        }

        // Two teacher rows, same email, one per institute — impossible under the
        // old global unique, correct under the per-tenant composite unique.
        $teachers = LmsTeacher::withoutGlobalScope(TenantScope::class)
            ->where('email', 'multi-owner@example.test')
            ->get();

        $this->assertCount(2, $teachers);
        $this->assertEqualsCanonicalizing($tenantIds, $teachers->pluck('tenant_id')->all());

        // The owner received a setup invitation for each institute they now run.
        Notification::assertSentToTimes($owner, OwnerSetupInvitation::class, 2);
    }

    public function test_verify_reports_finalizing_when_provisioning_fails_and_recovers_on_retry(): void
    {
        Notification::fake();

        $this->mock(PaystackService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->andReturn([
                'status' => true,
                'data' => ['authorization_url' => 'https://paystack.test/pay/abc123'],
            ]);
            $mock->shouldReceive('verifyTransaction')->andReturn([
                'status' => true,
                'data' => ['status' => 'success'],
            ]);
        });

        $this->postJson('/api/signup', $this->payload())->assertStatus(201);
        $tenant = Tenant::where('slug', 'test-academy')->first();
        $reference = $tenant->paystack_init_reference;

        // Force provisioning to blow up once, mid-run, AFTER the atomic
        // pending->active flip — the precise state that used to strand the tenant.
        $boom = new class extends TenantOnboardingService {
            public bool $fail = true;

            public function run(Tenant $tenant, ?User $owner = null, bool $notify = true): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('simulated provisioning failure');
                }

                return parent::run($tenant, $owner, $notify);
            }
        };
        $this->app->instance(TenantOnboardingService::class, $boom);

        // Payment is confirmed but provisioning fails: the browser must see a
        // clean 503 "finalizing", NOT a 500, and the tenant must be reverted to
        // `pending` so a retry can genuinely re-provision.
        $this->getJson('/api/signup/verify?reference=' . urlencode($reference))
            ->assertStatus(503)
            ->assertJsonPath('status', 'pending');

        $tenant->refresh();
        $this->assertSame('pending', $tenant->status);
        $this->assertDatabaseMissing('tenant_onboarding_audits', ['tenant_id' => $tenant->id]);

        // Retry with provisioning healthy: the same reference now completes.
        $boom->fail = false;
        $this->getJson('/api/signup/verify?reference=' . urlencode($reference))
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $tenant->refresh();
        $this->assertSame('active', $tenant->status);
        $this->assertDatabaseHas('tenant_onboarding_audits', ['tenant_id' => $tenant->id]);
    }
}
