<?php

namespace Tests\Feature;

use App\Models\LmsSession;
use App\Models\PlatformTransaction;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jorsas's own plan billing through Flutterwave in the academy's currency
 * (signup asks for the country), and the admin money totals across currencies.
 * Flutterwave is faked.
 */
class PlatformBillingMultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const FLW = 'https://api.flutterwave.com/v3/';

    private array $transactions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        config([
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-fake',
            'services.flutterwave.webhook_hash' => 'whsec-123',
            'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3',
            // Fixed local prices, so no exchange-rate lookups in tests.
            'saas.plans.basic.prices' => ['GHS' => 300],
            'saas.plans.pro.prices' => ['GHS' => 900],
        ]);

        Http::fake([
            self::FLW . 'payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/plan']]),
            self::FLW . 'transactions/verify_by_reference*' => function (HttpRequest $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                $txn = $this->transactions[$q['tx_ref'] ?? ''] ?? null;

                return $txn
                    ? Http::response(['status' => 'success', 'data' => $txn])
                    : Http::response(['status' => 'error', 'message' => 'No transaction was found'], 400);
            },
            '*' => Http::response(['status' => true, 'data' => []]),
        ]);
    }

    private function paid(string $ref, float $amount, string $currency): void
    {
        $this->transactions[$ref] = [
            'id' => 1, 'tx_ref' => $ref, 'status' => 'successful', 'amount' => $amount, 'currency' => $currency,
            'payment_type' => 'card', 'customer' => ['email' => 'kofi@academy.test'],
        ];
    }

    private function signup(array $overrides = [])
    {
        return $this->postJson('/api/signup', array_merge([
            'name' => 'Accra Code School', 'slug' => 'accra-code', 'admin_name' => 'Kofi Mensah',
            'admin_email' => 'kofi@academy.test', 'plan' => 'basic', 'niche' => 'Technology', 'country' => 'GH',
        ], $overrides));
    }

    private function ownerToken(Tenant $tenant): string
    {
        $user = User::create([
            'first_name' => 'Kofi', 'last_name' => 'Mensah', 'username' => 'owner-' . $tenant->slug,
            'email' => 'owner@' . $tenant->slug . '.test', 'password' => Hash::make('secret123'),
        ]);
        DB::table('tenant_admins')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
        $token = Str::random(80);
        app()->instance('currentTenant', $tenant);
        LmsSession::create(['role' => 'owner', 'user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        app()->forgetInstance('currentTenant');

        return $token;
    }

    // ── Signup ─────────────────────────────────────────────────────────────

    public function test_signup_takes_the_country_and_charges_the_plan_in_its_currency(): void
    {
        $this->signup()->assertCreated()->assertJsonPath('authorization_url', 'https://checkout.flutterwave.test/plan');

        $tenant = Tenant::where('slug', 'accra-code')->sole();
        $this->assertSame('GHS', $tenant->currency());
        $this->assertSame('GH', $tenant->payoutCountry());
        $this->assertSame('pending', $tenant->status);

        $tx = PlatformTransaction::where('reference', $tenant->paystack_init_reference)->sole();
        $this->assertSame('flutterwave', $tx->gateway);
        $this->assertSame('GHS', $tx->currency);
        $this->assertEquals(300.0, (float) $tx->amount);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::FLW . 'payments'
            && $r['currency'] === 'GHS' && (float) $r['amount'] === 300.0
            && ! isset($r['subaccounts']) // plan money is the platform's own: no split
            && $r['meta']['purpose'] === 'tenant_signup');
    }

    public function test_an_unsupported_country_is_refused(): void
    {
        $this->signup(['country' => 'ZZ'])->assertStatus(422)->assertJsonValidationErrors('country');
    }

    public function test_signup_verify_activates_after_flutterwave_confirms(): void
    {
        $this->signup()->assertCreated();
        $tenant = Tenant::where('slug', 'accra-code')->sole();
        $ref = $tenant->paystack_init_reference;

        $this->getJson('/api/signup/verify?reference=' . $ref)->assertStatus(402);
        $this->assertSame('pending', $tenant->fresh()->status);

        $this->paid($ref, 300, 'GHS');
        $this->getJson('/api/signup/verify?reference=' . $ref)->assertOk()->assertJsonPath('status', 'success');
        $this->getJson('/api/signup/verify?reference=' . $ref)->assertOk();

        $tenant->refresh();
        $this->assertSame('active', $tenant->status);
        $this->assertSame('basic', $tenant->plan);
        $this->assertSame('success', PlatformTransaction::where('reference', $ref)->value('status'));
        $this->assertDatabaseCount('tenant_onboarding_audits', 1);
    }

    public function test_a_wrong_amount_is_held_for_review_and_nothing_is_activated(): void
    {
        $this->signup()->assertCreated();
        $tenant = Tenant::where('slug', 'accra-code')->sole();
        $ref = $tenant->paystack_init_reference;

        $this->paid($ref, 3, 'GHS');
        $this->getJson('/api/signup/verify?reference=' . $ref)->assertStatus(402);

        $this->assertSame('pending', $tenant->fresh()->status);
        $this->assertSame('review', PlatformTransaction::where('reference', $ref)->value('status'));
    }

    public function test_the_flutterwave_webhook_activates_a_paid_signup(): void
    {
        $this->signup()->assertCreated();
        $tenant = Tenant::where('slug', 'accra-code')->sole();
        $ref = $tenant->paystack_init_reference;
        $this->paid($ref, 300, 'GHS');

        $this->postJson('/api/frontend/flutterwave/webhook', ['event' => 'charge.completed', 'data' => ['tx_ref' => $ref]], ['verif-hash' => 'whsec-123'])
            ->assertOk();

        $this->assertSame('active', $tenant->fresh()->status);
    }

    // ── Upgrades ───────────────────────────────────────────────────────────

    public function test_an_upgrade_is_charged_in_the_academy_currency_and_applied_once(): void
    {
        $tenant = Tenant::create(['name' => 'Accra', 'slug' => 'accra', 'settings' => ['country' => 'GH', 'currency' => 'GHS']]);
        $token = $this->ownerToken($tenant);

        $this->withToken($token)->getJson('/api/frontend/lms/billing/status')
            ->assertOk()
            ->assertJsonPath('currency', 'GHS')
            ->assertJsonFragment(['slug' => 'pro', 'local_price' => 900, 'currency' => 'GHS']);

        $ref = $this->withToken($token)->postJson('/api/frontend/lms/billing/checkout', ['plan' => 'pro'])
            ->assertOk()->assertJsonPath('currency', 'GHS')->json('reference');

        $this->paid($ref, 900, 'GHS');
        $this->withToken($token)->getJson('/api/frontend/lms/billing/verify?reference=' . $ref)
            ->assertOk()->assertJsonPath('plan', 'pro');
        $end = $tenant->fresh()->current_period_end;

        // A second confirmation must not extend the plan again.
        $this->withToken($token)->getJson('/api/frontend/lms/billing/verify?reference=' . $ref)->assertOk();
        $this->assertTrue($tenant->fresh()->current_period_end->equalTo($end));
    }

    public function test_an_upgrade_payment_of_another_academy_is_refused(): void
    {
        $mine = Tenant::create(['name' => 'Mine', 'slug' => 'mine']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        PlatformTransaction::recordPending($other, 'JORSAS-UPG-OTHER', 900, 'plan_upgrade', 'pro', 'GHS', 'flutterwave');

        $this->withToken($this->ownerToken($mine))->getJson('/api/frontend/lms/billing/verify?reference=JORSAS-UPG-OTHER')
            ->assertStatus(403);
    }

    // ── Public plans ───────────────────────────────────────────────────────

    public function test_the_plans_endpoint_prices_in_the_chosen_country(): void
    {
        $json = $this->getJson('/api/plans?country=GH')->assertOk()->json();

        $basic = collect($json['plans'])->firstWhere('slug', 'basic');
        $this->assertEquals(300, $basic['local_price']);
        $this->assertSame('GHS', $basic['currency']);
        $this->assertContains('GH', array_column($json['countries'], 'code'));
    }

    // ── Admin totals ───────────────────────────────────────────────────────

    public function test_the_admin_pages_show_naira_totals_with_the_currency_breakdown(): void
    {
        Cache::put('fx:ngn:rates', ['NGN' => 1, 'GHS' => 0.01], now()->addHour());
        Tenant::create(['name' => 'Jorsas', 'slug' => (string) config('saas.primary_slug', 'jorsas')]);
        $ng = Tenant::create(['name' => 'Lagos Academy', 'slug' => 'lagos']);
        $gh = Tenant::create(['name' => 'Accra Academy', 'slug' => 'accra', 'settings' => ['country' => 'GH', 'currency' => 'GHS']]);

        PlatformTransaction::recordPending($ng, 'P-NG', 5000, 'plan_upgrade', 'basic', 'NGN');
        PlatformTransaction::markSuccess($ng, 'P-NG', 'plan_upgrade', 'basic', ['amount' => 500000, 'currency' => 'NGN']);
        PlatformTransaction::recordPending($gh, 'P-GH', 300, 'plan_upgrade', 'basic', 'GHS', 'flutterwave');
        PlatformTransaction::markSuccess($gh, 'P-GH', 'plan_upgrade', 'basic', ['gateway' => 'flutterwave', 'amount' => 30000, 'currency' => 'GHS']);

        $admin = User::create([
            'first_name' => 'Host', 'last_name' => 'Admin', 'username' => 'host', 'email' => 'host@jorsastech.test',
            'password' => Hash::make('secret123'),
        ]);
        $admin->forceFill(['super_user' => 1])->save();
        // The Botble admin shell expects its own ACL user model.
        $admin = \Botble\ACL\Models\User::query()->findOrFail($admin->id);

        $dir = trim((string) config('saas.admin_dir', 'admin'), '/');

        // 5,000 NGN + 300 GHS (= 30,000 NGN) = 35,000 NGN.
        $this->actingAs($admin)->get('/' . $dir . '/lms/transactions')
            ->assertOk()
            ->assertSee('₦35,000.00', false)
            ->assertSee('NGN 5,000.00 · GHS 300.00', false);

        $this->actingAs($admin)->get('/' . $dir . '/lms')
            ->assertOk()
            ->assertSee('₦35,000', false)
            ->assertSee('Total in naira at today', false);
    }

    public function test_sales_of_removed_academies_still_add_up_in_the_tables(): void
    {
        Tenant::create(['name' => 'Jorsas', 'slug' => (string) config('saas.primary_slug', 'jorsas')]);
        $live = Tenant::create(['name' => 'Lagos Academy', 'slug' => 'lagos']);

        // One sale by a live academy, one by an academy whose row is gone (#999).
        foreach ([[$live->id, 'live@x.test', 30000], [999, 'gone@x.test', 50000]] as [$tenantId, $email, $amount]) {
            $reg = \App\Models\TrainingRegistration::createForTenant($tenantId, [
                'first_name' => 'A', 'last_name' => 'B', 'date_of_birth' => '2000-01-01', 'qualification_level' => 'SSCE',
                'phone_number' => '0800', 'email' => $email, 'whatsapp' => '0800', 'course_name' => 'X', 'learning_mode' => 'live',
                'course_price' => $amount, 'status' => 'approved',
            ]);
            \App\Models\Payment::createForTenant($tenantId, [
                'registration_id' => $reg->id, 'reference' => 'R-' . $tenantId, 'amount' => $amount,
                'currency' => 'NGN', 'status' => 'success', 'gateway' => 'paystack', 'kind' => 'initial', 'platform_fee' => $amount * 0.05,
            ]);
        }

        $admin = User::create([
            'first_name' => 'Host', 'last_name' => 'Admin', 'username' => 'host', 'email' => 'host@jorsastech.test',
            'password' => Hash::make('secret123'),
        ]);
        $admin->forceFill(['super_user' => 1])->save();
        $admin = \Botble\ACL\Models\User::query()->findOrFail($admin->id);
        $dir = trim((string) config('saas.admin_dir', 'admin'), '/');

        $this->actingAs($admin)->get('/' . $dir . '/lms')
            ->assertOk()
            ->assertSee('Removed academies (1)', false)
            ->assertSee('Total, all in naira', false)
            ->assertSeeInOrder(['Total', '₦80,000', '₦4,000'], false);

        $this->actingAs($admin)->get('/' . $dir . '/lms/transactions')
            ->assertOk()
            ->assertSee('Removed academy (#999)', false)
            ->assertSee('₦80,000.00', false);
    }

    public function test_money_is_kept_per_currency_and_totalled_in_naira(): void
    {
        // 1 NGN = 0.01 GHS and 0.0006 USD; no rate for XYZ.
        Cache::put('fx:ngn:rates', ['NGN' => 1, 'GHS' => 0.01, 'USD' => 0.0006], now()->addHour());

        $money = PlatformMoney::total(['NGN' => 50000, 'GHS' => 300, 'USD' => 12, 'XYZ' => 5]);

        $this->assertEquals(50000 + 30000 + 20000, $money['ngn']);
        $this->assertSame(['NGN', 'GHS', 'USD', 'XYZ'], array_keys($money['breakdown']));
        $this->assertSame(['XYZ'], $money['unconverted']);
        $this->assertSame('NGN 50,000.00 · GHS 300.00 · USD 12.00 · XYZ 5.00', PlatformMoney::breakdownLine($money['breakdown']));
        $this->assertSame('', PlatformMoney::breakdownLine(['NGN' => 100]));
    }
}
