<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner Agents page (OwnerAgentController): the academy's Admission Marketers
 * with their referral numbers and payout balance. The per-agent wallet math
 * mirrors the agent's own dashboard, so these tests pin the two surfaces to the
 * same numbers, plus the three guards: plan gate (Basic+ → 402), owner-only
 * authorization (a student bearer is 403), and tenant scoping (another
 * academy's agent id 404s before any write happens).
 */
class OwnerAgentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.enforce_tenancy' => true]);
    }

    private function makeTenant(string $slug, string $plan = 'basic'): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'plan' => $plan]);
    }

    /** Run $fn with $tenant bound, mimicking a resolved request, then unbind. */
    private function asTenant(Tenant $tenant, callable $fn)
    {
        app()->instance('currentTenant', $tenant);
        try {
            return $fn();
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    /** Owner user + tenant_admins row + owner session, returns the bearer token. */
    private function ownerToken(Tenant $tenant): string
    {
        $email = 'owner@' . $tenant->slug . '.test';
        $user = User::create([
            'first_name' => 'Owner',
            'last_name' => ucfirst($tenant->slug),
            'username' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
        DB::table('tenant_admins')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $token = Str::random(80);
        $this->asTenant($tenant, fn () => LmsSession::create([
            'role' => 'owner',
            'user_id' => $user->id,
            'token' => $token,
            'tenant_id' => $tenant->id,
            'expires_at' => now()->addDay(),
        ]));

        return $token;
    }

    private function makeAgent(Tenant $tenant, string $name, string $status = 'approved'): Agent
    {
        return $this->asTenant($tenant, fn () => Agent::create([
            'name' => $name,
            'email' => Str::slug($name) . '@' . $tenant->slug . '.test',
            'phone' => '08000000000',
            'home_address' => 'Somewhere',
            'qualification' => 'Diploma',
            'custom_answers' => [],
            'referral_code' => 'AGENT-' . strtoupper(Str::random(6)),
            'status' => $status,
            'password' => Hash::make('secret123'),
        ]));
    }

    public function test_index_lists_agents_with_matching_wallet_numbers(): void
    {
        $tenant = $this->makeTenant('acme');
        $token = $this->ownerToken($tenant);
        $agent = $this->makeAgent($tenant, 'Top Marketer');
        $this->makeAgent($tenant, 'Waiting', 'pending');

        $this->asTenant($tenant, function () use ($agent) {
            LmsStudent::create([
                'first_name' => 'Referred',
                'last_name' => 'One',
                'email' => 'referred@acme.test',
                'password' => Hash::make('secret123'),
                'referred_by_agent_id' => $agent->id,
            ]);
            // Earned 10k, requested a 4k payout that is still pending → balance 6k.
            AgentCommission::create([
                'agent_id' => $agent->id,
                'course_price' => 50000,
                'commission_amount' => 10000,
                'status' => 'pending',
                'type' => 'referral',
            ]);
            AgentCommission::create([
                'agent_id' => $agent->id,
                'course_price' => 0,
                'commission_amount' => -4000,
                'status' => 'withdrawal_requested',
                'type' => 'withdrawal',
            ]);
        });

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/owner/agents')
            ->assertOk()
            ->assertJsonCount(2, 'agents');

        $row = collect($res->json('agents'))->firstWhere('id', $agent->id);
        $this->assertSame(1, $row['students_referred']);
        // json_decode folds 10000.0 to int, so cast before the identical check.
        $this->assertSame(10000.0, (float) $row['total_earned']);
        $this->assertSame(6000.0, (float) $row['balance']);

        $totals = $res->json('totals');
        $this->assertSame(2, $totals['agents']);
        $this->assertSame(1, $totals['approved']);
        $this->assertSame(1, $totals['pending']);
        $this->assertSame(1, $totals['students_referred']);
        $this->assertSame(6000.0, (float) $totals['total_balance']);
    }

    public function test_agents_of_other_academies_never_leak_into_the_list(): void
    {
        $acme = $this->makeTenant('acme');
        $acmeToken = $this->ownerToken($acme);
        $beta = $this->makeTenant('beta');
        $this->makeAgent($beta, 'Beta Only');
        $this->makeAgent($acme, 'Acme Agent');

        $res = $this->withHeader('Authorization', 'Bearer ' . $acmeToken)
            ->getJson('/api/frontend/lms/owner/agents')
            ->assertOk();

        $names = array_column($res->json('agents'), 'name');
        $this->assertEqualsCanonicalizing(['Acme Agent'], $names);
    }

    public function test_free_plan_academy_gets_the_basic_gate_402(): void
    {
        $tenant = $this->makeTenant('freebie', 'free');
        $token = $this->ownerToken($tenant);
        $this->makeAgent($tenant, 'Someone');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/owner/agents')
            ->assertStatus(402);
    }

    public function test_only_owners_can_list_and_approve(): void
    {
        $tenant = $this->makeTenant('acme');
        $agent = $this->makeAgent($tenant, 'Pending Person', 'pending');

        // A student session is bound to the same tenant, but is not in
        // tenant_admins: authorization must fail closed.
        $studentToken = Str::random(80);
        $this->asTenant($tenant, fn () => LmsSession::create([
            'role' => 'student',
            'user_id' => 1,
            'token' => $studentToken,
            'tenant_id' => $tenant->id,
            'expires_at' => now()->addDay(),
        ]));

        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->getJson('/api/frontend/lms/owner/agents')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer ' . $studentToken)
            ->postJson("/api/frontend/lms/owner/agents/{$agent->id}/approve")
            ->assertStatus(403);
    }

    public function test_approve_scopes_to_own_academy_and_sets_status(): void
    {
        $acme = $this->makeTenant('acme');
        $acmeToken = $this->ownerToken($acme);
        $beta = $this->makeTenant('beta');
        $betaAgent = $this->makeAgent($beta, 'Beta Agent', 'pending');

        // Cross-academy id must 404 (the tenant scope fires first), not approve.
        $this->withHeader('Authorization', 'Bearer ' . $acmeToken)
            ->postJson("/api/frontend/lms/owner/agents/{$betaAgent->id}/approve")
            ->assertNotFound();

        $this->assertSame('pending', $betaAgent->fresh()->status);

        $acmeAgent = $this->makeAgent($acme, 'Acme Agent', 'pending');
        $this->withHeader('Authorization', 'Bearer ' . $acmeToken)
            ->postJson("/api/frontend/lms/owner/agents/{$acmeAgent->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $acmeAgent->fresh()->status);
        $this->assertNotNull($acmeAgent->fresh()->approved_at);
    }

    public function test_revoke_rejected_agent_loses_portal_access(): void
    {
        $tenant = $this->makeTenant('acme');
        $token = $this->ownerToken($tenant);
        $agent = $this->makeAgent($tenant, 'To Revoke', 'approved');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/frontend/lms/owner/agents/{$agent->id}/reject")
            ->assertOk();

        $this->assertSame('rejected', $agent->fresh()->status);
    }

    public function test_approve_emails_a_new_temporary_password(): void
    {
        // Regression guard for the silent email failure: the mailable REQUIRES
        // a third $password argument — omitting it throws inside the catch, so
        // approval succeeded but no email ever left (and the agent had no way
        // to sign in).
        Mail::fake();
        config(['saas.training_email_enabled' => true]);

        $tenant = $this->makeTenant('acme');
        $token = $this->ownerToken($tenant);
        $agent = $this->makeAgent($tenant, 'New Agent', 'pending');
        $oldHash = $agent->password;

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/frontend/lms/owner/agents/{$agent->id}/approve")
            ->assertOk();
        $this->assertTrue($res->json('email_sent'));

        Mail::assertSent(\App\Mail\AgentApplicationApprovedMail::class, function ($mail) use ($agent) {
            return $mail->agent->id === $agent->id && $mail->password !== '' && strlen($mail->password) === 12;
        });

        // The emailed password is the one now stored (hashed), not the old one.
        $this->assertNotSame($oldHash, $agent->fresh()->password);
        $this->assertTrue(Hash::check(collect(Mail::sent(\App\Mail\AgentApplicationApprovedMail::class))->first()->password, $agent->fresh()->password));
    }

    public function test_show_returns_per_agent_analytics(): void
    {
        $tenant = $this->makeTenant('acme');
        $token = $this->ownerToken($tenant);
        $agent = $this->makeAgent($tenant, 'Analytics Agent');

        $this->asTenant($tenant, function () use ($agent) {
            LmsStudent::create([
                'first_name' => 'Referred',
                'last_name' => 'Two',
                'email' => 'referred2@acme.test',
                'password' => Hash::make('secret123'),
                'referred_by_agent_id' => $agent->id,
            ]);
            AgentCommission::create([
                'agent_id' => $agent->id,
                'enrollment_id' => 77,
                'course_price' => 50000,
                'commission_amount' => 7500,
                'status' => 'pending',
                'type' => 'referral',
            ]);
        });

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/frontend/lms/owner/agents/{$agent->id}")
            ->assertOk();

        $this->assertSame('Analytics Agent', $res->json('agent.name'));
        $this->assertSame(1, $res->json('stats.students_referred'));
        $this->assertSame(7500.0, (float) $res->json('stats.total_earned'));
        $this->assertSame(7500.0, (float) $res->json('stats.balance'));
        $this->assertCount(1, $res->json('referrals'));
        $this->assertCount(1, $res->json('transactions'));
    }

    public function test_show_is_scoped_to_own_academy(): void
    {
        $acme = $this->makeTenant('acme');
        $acmeToken = $this->ownerToken($acme);
        $beta = $this->makeTenant('beta');
        $betaAgent = $this->makeAgent($beta, 'Beta Agent');

        $this->withHeader('Authorization', 'Bearer ' . $acmeToken)
            ->getJson("/api/frontend/lms/owner/agents/{$betaAgent->id}")
            ->assertNotFound();
    }
}
