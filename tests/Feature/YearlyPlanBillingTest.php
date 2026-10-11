<?php

namespace Tests\Feature;

use App\Models\LmsSession;
use App\Models\PlatformTransaction;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlanPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Yearly plan billing: an academy can pay for 12 months at once at 12x the
 * monthly price less the yearly discount (20%), in its own currency. Paying
 * yearly buys a year; while that year runs, checkouts stay yearly.
 * Flutterwave is faked.
 */
class YearlyPlanBillingTest extends TestCase
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
            'saas.plans.basic.price' => 5000,
            'saas.plans.pro.price' => 15000,
            'saas.plans.basic.prices' => ['GHS' => 300],
            'saas.plans.pro.prices' => ['GHS' => 900],
            'saas.yearly_discount_percent' => 20,
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

    private function ghanaAcademy(): Tenant
    {
        return Tenant::create(['name' => 'Accra', 'slug' => 'accra', 'settings' => ['country' => 'GH', 'currency' => 'GHS']]);
    }

    public function test_yearly_is_twelve_months_less_twenty_percent_in_every_currency(): void
    {
        $this->assertSame(['amount' => 5000.0, 'currency' => 'NGN'], PlanPricing::priceIn('basic', 'NGN'));
        $this->assertSame(['amount' => 48000.0, 'currency' => 'NGN'], PlanPricing::priceIn('basic', 'NGN', 'yearly'));
        $this->assertSame(['amount' => 2880.0, 'currency' => 'GHS'], PlanPricing::priceIn('basic', 'GHS', 'yearly'));
        $this->assertSame(['amount' => 0.0, 'currency' => 'NGN'], PlanPricing::priceIn('free', 'NGN', 'yearly'));
        $this->assertNull(PlanPricing::priceIn('enterprise', 'NGN', 'yearly'));
    }

    public function test_the_billing_page_gets_yearly_prices_and_the_current_cycle(): void
    {
        $tenant = $this->ghanaAcademy();
        $token = $this->ownerToken($tenant);

        $this->withToken($token)->getJson('/api/frontend/lms/billing/status')
            ->assertOk()
            ->assertJsonPath('billing_cycle', 'monthly')
            ->assertJsonPath('yearly_discount_percent', 20)
            ->assertJsonFragment(['slug' => 'pro', 'local_price' => 900, 'local_yearly_price' => 8640, 'yearly_price' => 144000]);
    }

    public function test_a_yearly_upgrade_charges_the_year_and_buys_twelve_months(): void
    {
        $tenant = $this->ghanaAcademy();
        $token = $this->ownerToken($tenant);

        $ref = $this->withToken($token)->postJson('/api/frontend/lms/billing/checkout', ['plan' => 'pro', 'billing_cycle' => 'yearly'])
            ->assertOk()->assertJsonPath('billing_cycle', 'yearly')->json('reference');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::FLW . 'payments'
            && $r['currency'] === 'GHS' && (float) $r['amount'] === 8640.0 && $r['meta']['billing_cycle'] === 'yearly');

        $this->paid($ref, 8640, 'GHS');
        $this->withToken($token)->getJson('/api/frontend/lms/billing/verify?reference=' . $ref)->assertOk()->assertJsonPath('plan', 'pro');

        $tenant->refresh();
        $this->assertSame('yearly', $tenant->billingCycle());
        $this->assertTrue($tenant->current_period_end->between(now()->addYear()->subDay(), now()->addYear()->addDay()));
        $this->assertSame('yearly', PlatformTransaction::where('reference', $ref)->value('billing_cycle'));
    }

    public function test_monthly_stays_a_month_by_default(): void
    {
        $tenant = $this->ghanaAcademy();
        $token = $this->ownerToken($tenant);

        $ref = $this->withToken($token)->postJson('/api/frontend/lms/billing/checkout', ['plan' => 'basic'])->assertOk()->json('reference');
        $this->paid($ref, 300, 'GHS');
        $this->withToken($token)->getJson('/api/frontend/lms/billing/verify?reference=' . $ref)->assertOk();

        $tenant->refresh();
        $this->assertSame('monthly', $tenant->billingCycle());
        $this->assertTrue($tenant->current_period_end->lessThan(now()->addDays(32)));
    }

    public function test_while_a_paid_year_runs_checkouts_stay_yearly(): void
    {
        $tenant = $this->ghanaAcademy();
        $tenant->activatePlan('basic', 'yearly');
        $token = $this->ownerToken($tenant);

        $this->withToken($token)->getJson('/api/frontend/lms/billing/status')
            ->assertOk()->assertJsonPath('billing_cycle', 'yearly')->assertJsonPath('monthly_locked_until', fn ($v) => $v !== null);

        // Monthly would trade the rest of the year for one month: refused.
        $this->withToken($token)->postJson('/api/frontend/lms/billing/checkout', ['plan' => 'pro', 'billing_cycle' => 'monthly'])
            ->assertStatus(422)->assertJsonPath('cycle_locked', true);

        // Upgrading yearly is fine.
        $this->withToken($token)->postJson('/api/frontend/lms/billing/checkout', ['plan' => 'pro', 'billing_cycle' => 'yearly'])->assertOk();
    }

    public function test_signup_can_pay_yearly(): void
    {
        $this->postJson('/api/signup', [
            'name' => 'Accra Code School', 'slug' => 'accra-code', 'admin_name' => 'Kofi Mensah',
            'admin_email' => 'kofi@academy.test', 'plan' => 'basic', 'niche' => 'Technology', 'country' => 'GH',
            'billing_cycle' => 'yearly',
        ])->assertCreated();

        $tenant = Tenant::where('slug', 'accra-code')->sole();
        $ref = $tenant->paystack_init_reference;
        $tx = PlatformTransaction::where('reference', $ref)->sole();
        $this->assertEquals(2880.0, (float) $tx->amount);
        $this->assertSame('yearly', $tx->billing_cycle);

        $this->paid($ref, 2880, 'GHS');
        $this->getJson('/api/signup/verify?reference=' . $ref)->assertOk()->assertJsonPath('status', 'success');

        $tenant->refresh();
        $this->assertSame('basic', $tenant->plan);
        $this->assertSame('yearly', $tenant->billingCycle());
        $this->assertTrue($tenant->current_period_end->greaterThan(now()->addMonths(11)));
    }
}
