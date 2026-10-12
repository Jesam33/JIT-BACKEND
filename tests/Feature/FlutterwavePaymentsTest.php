<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\CoursePayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Student payments through Flutterwave, in the academy's own currency, with
 * the platform service charge split off and the rest paid to the academy's
 * bank (a Flutterwave subaccount). Flutterwave itself is faked.
 */
class FlutterwavePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const FLW = 'https://api.flutterwave.com/v3/';

    /** What the fake Flutterwave reports for verify_by_reference, keyed by tx_ref. */
    private array $transactions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'saas.enforce_tenancy' => true,
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-fake',
            'services.flutterwave.webhook_hash' => 'whsec-123',
            'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3',
        ]);
        $this->fakeFlutterwave();
    }

    private function fakeFlutterwave(array $overrides = []): void
    {
        Http::fake($overrides + [
            self::FLW . 'subaccounts' => Http::response(['status' => 'success', 'message' => 'Subaccount created', 'data' => [
                'id' => 501, 'subaccount_id' => 'RS_NEW', 'full_name' => 'Kofi Mensah', 'bank_name' => 'GCB Bank',
            ]]),
            self::FLW . 'subaccounts/*' => Http::response(['status' => 'success', 'message' => 'Subaccount deleted']),
            self::FLW . 'banks/GH' => Http::response(['status' => 'success', 'data' => [
                ['id' => 1, 'code' => 'GH190100', 'name' => 'GCB Bank'],
            ]]),
            self::FLW . 'payments' => Http::response(['status' => 'success', 'message' => 'Hosted Link', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/abc']]),
            self::FLW . 'tokenized-charges' => function (HttpRequest $request) {
                return Http::response(['status' => 'success', 'data' => $this->txn($request['tx_ref'], (float) $request['amount'], $request['currency'], 'card-token-1')]);
            },
            self::FLW . 'transactions/verify_by_reference*' => function (HttpRequest $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                $txn = $this->transactions[$q['tx_ref'] ?? ''] ?? null;

                return $txn
                    ? Http::response(['status' => 'success', 'data' => $txn])
                    : Http::response(['status' => 'error', 'message' => 'No transaction was found for this id'], 400);
            },
            // Anything else (e.g. Paystack bank lists on the owner page): harmless.
            '*' => Http::response(['status' => true, 'data' => []]),
        ]);
    }

    private function txn(string $ref, float $amount, string $currency, ?string $token = 'card-token-1'): array
    {
        return [
            'id' => random_int(1000, 9999), 'tx_ref' => $ref, 'status' => 'successful',
            'amount' => $amount, 'currency' => $currency, 'payment_type' => 'card',
            'customer' => ['email' => 'kwame@student.test'],
            'card' => $token ? ['token' => $token, 'last_4digits' => '4242', 'type' => 'VISA', 'expiry' => '09/32'] : [],
        ];
    }

    private function academy(string $slug, array $settings = []): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug) . ' Academy', 'slug' => $slug, 'plan' => 'basic',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
            'settings' => $settings,
        ]);
    }

    /** A Ghanaian academy already linked on Flutterwave. */
    private function ghana(): Tenant
    {
        return $this->academy('accra', [
            'country' => 'GH', 'currency' => 'GHS',
            'flutterwave' => ['subaccount_id' => 'RS_GH', 'id' => 77, 'country' => 'GH', 'account_number' => '1234567890'],
        ]);
    }

    private function asTenant(Tenant $tenant, callable $fn)
    {
        app()->instance('currentTenant', $tenant);
        try {
            return $fn();
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    private function course(Tenant $tenant, array $attrs = []): LmsCourse
    {
        return $this->asTenant($tenant, function () use ($attrs) {
            $teacher = LmsTeacher::create(['name' => 'Ama', 'email' => 'ama' . Str::random(4) . '@t.test', 'password' => 'x']);
            $course = LmsCourse::create(['title' => 'Data Science', 'slug' => 'data-' . Str::random(4), 'price' => 1200, 'is_active' => true]);
            LmsTrack::create(['name' => 'Cohort 1', 'course_id' => $course->id, 'instructor_id' => $teacher->id, 'end_date' => now()->addYear()->toDateString()]);
            if ($attrs) {
                $course->update($attrs);
            }

            return $course->fresh();
        });
    }

    private function register(Tenant $tenant, LmsCourse $course, string $email = 'kwame@student.test')
    {
        return $this->postJson('/api/frontend/training/register', [
            'first_name' => 'Kwame', 'last_name' => 'Boateng', 'date_of_birth' => '2000-01-01',
            'qualification_level' => 'SSCE', 'phone_number' => '0240000000', 'email' => $email,
            'whatsapp' => '0240000000', 'course_id' => $course->id, 'learning_mode' => 'live',
            'institute_slug' => $tenant->slug,
        ]);
    }

    private function ownerToken(Tenant $tenant): string
    {
        $user = User::create([
            'first_name' => 'Kofi', 'last_name' => 'Mensah', 'username' => 'owner-' . $tenant->slug,
            'email' => 'owner@' . $tenant->slug . '.test', 'password' => Hash::make('secret123'),
        ]);
        DB::table('tenant_admins')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
        $token = Str::random(80);
        $this->asTenant($tenant, fn () => LmsSession::create(['role' => 'owner', 'user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]));

        return $token;
    }

    // ── Checkout ───────────────────────────────────────────────────────────

    public function test_a_ghanaian_academy_charges_in_cedis_with_the_split(): void
    {
        $gh = $this->ghana();
        $course = $this->course($gh);

        $id = $this->register($gh, $course)->assertCreated()
            ->assertJsonPath('course.charge_currency', 'GHS')
            ->json('registration_id');

        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])
            ->assertOk()
            ->assertJsonPath('authorization_url', 'https://checkout.flutterwave.test/pay/abc');

        $payment = Payment::withoutGlobalScope(TenantScope::class)->where('registration_id', $id)->sole();
        $this->assertSame('flutterwave', $payment->gateway);
        $this->assertSame('GHS', $payment->currency);
        $this->assertEquals(1200.0, (float) $payment->amount);
        $fee = round(1200 * $gh->commissionPercent() / 100, 2);
        $this->assertEquals($fee, (float) $payment->platform_fee);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::FLW . 'payments'
            && $r['currency'] === 'GHS'
            && (float) $r['amount'] === 1200.0
            && $r['payment_options'] === 'card,mobilemoneyghana'
            && $r['subaccounts'][0]['id'] === 'RS_GH'
            && $r['subaccounts'][0]['transaction_charge_type'] === 'flat_subaccount'
            && (float) $r['subaccounts'][0]['transaction_charge'] === $fee
            && $r['tx_ref'] === $payment->reference);
    }

    public function test_verify_confirms_the_payment_and_saves_the_card_for_monthly_renewals(): void
    {
        $gh = $this->ghana();
        $course = $this->course($gh, ['billing_type' => 'monthly']);
        $id = $this->register($gh, $course)->json('registration_id');
        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])->assertOk();
        $payment = Payment::withoutGlobalScope(TenantScope::class)->where('registration_id', $id)->sole();

        // Not paid yet.
        $this->getJson('/api/frontend/paystack/verify?reference=' . $payment->reference)->assertJsonPath('status', 'pending');

        $this->transactions[$payment->reference] = $this->txn($payment->reference, 1200, 'GHS');
        $this->getJson('/api/frontend/paystack/verify?reference=' . $payment->reference)->assertJsonPath('status', 'success');

        $registration = TrainingRegistration::withoutGlobalScope(TenantScope::class)->find($id);
        $this->assertSame('approved', $registration->status);
        $this->assertSame('success', $payment->fresh()->status);
        $this->assertNotNull($registration->paid_until);
        $card = \App\Services\CourseBilling::authorization($registration);
        $this->assertSame('flutterwave', $card['gateway']);
        $this->assertSame('card-token-1', $card['code']);
        $this->assertSame('4242', $card['last4']);
    }

    public function test_a_wrong_amount_or_currency_is_held_for_review(): void
    {
        $gh = $this->ghana();
        $id = $this->register($gh, $this->course($gh))->json('registration_id');
        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])->assertOk();
        $payment = Payment::withoutGlobalScope(TenantScope::class)->where('registration_id', $id)->sole();

        $this->transactions[$payment->reference] = $this->txn($payment->reference, 1200, 'NGN');

        $this->getJson('/api/frontend/paystack/verify?reference=' . $payment->reference)->assertJsonPath('status', 'review');
        $this->assertSame('pending', TrainingRegistration::withoutGlobalScope(TenantScope::class)->find($id)->status);
    }

    public function test_the_webhook_needs_the_secret_hash_and_rechecks_with_flutterwave(): void
    {
        $gh = $this->ghana();
        $id = $this->register($gh, $this->course($gh))->json('registration_id');
        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])->assertOk();
        $payment = Payment::withoutGlobalScope(TenantScope::class)->where('registration_id', $id)->sole();
        $body = ['event' => 'charge.completed', 'data' => ['tx_ref' => $payment->reference, 'status' => 'successful']];

        $this->postJson('/api/frontend/flutterwave/webhook', $body, ['verif-hash' => 'wrong'])->assertStatus(401);

        // A forged "successful" body alone grants nothing: Flutterwave says unpaid.
        $this->postJson('/api/frontend/flutterwave/webhook', $body, ['verif-hash' => 'whsec-123'])->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);

        $this->transactions[$payment->reference] = $this->txn($payment->reference, 1200, 'GHS');
        $this->postJson('/api/frontend/flutterwave/webhook', $body, ['verif-hash' => 'whsec-123'])->assertOk();
        $this->assertSame('success', $payment->fresh()->status);
    }

    public function test_an_academy_without_a_linked_bank_cannot_take_payments(): void
    {
        $unlinked = $this->academy('kumasi', ['country' => 'GH', 'currency' => 'GHS']);
        $id = $this->register($unlinked, $this->course($unlinked))->json('registration_id');

        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])->assertStatus(409);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'flutterwave.com/v3/payments'));
    }

    public function test_monthly_renewals_charge_the_saved_card_through_flutterwave(): void
    {
        $gh = $this->ghana();
        $course = $this->course($gh, ['billing_type' => 'monthly']);
        $id = $this->register($gh, $course)->json('registration_id');
        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])->assertOk();
        $first = Payment::withoutGlobalScope(TenantScope::class)->where('registration_id', $id)->sole();
        $this->transactions[$first->reference] = $this->txn($first->reference, 1200, 'GHS');
        $this->getJson('/api/frontend/paystack/verify?reference=' . $first->reference)->assertJsonPath('status', 'success');

        $registration = TrainingRegistration::withoutGlobalScope(TenantScope::class)->find($id);
        $due = $registration->paid_until->copy();

        Carbon::setTestNow($due->copy()->addHour());
        Artisan::call('lms:process-course-renewals');
        Carbon::setTestNow();

        $renewal = Payment::withoutGlobalScope(TenantScope::class)->where('registration_id', $id)->where('kind', 'renewal')->sole();
        $this->assertSame('success', $renewal->status);
        $this->assertSame('flutterwave', $renewal->gateway);
        $this->assertTrue($registration->fresh()->paid_until->greaterThan($due));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::FLW . 'tokenized-charges'
            && $r['token'] === 'card-token-1'
            && $r['currency'] === 'GHS'
            && $r['subaccounts'][0]['id'] === 'RS_GH');
    }

    // ── Storefront ─────────────────────────────────────────────────────────

    public function test_the_storefront_prices_courses_in_the_academys_currency(): void
    {
        $gh = $this->ghana();
        $this->course($gh);

        $courses = $this->getJson('/api/frontend/i/accra')->assertOk()->json('courses');

        $this->assertSame('GHS', $courses[0]['currency']);
        $this->assertSame('GHS', $courses[0]['charge_currency']);
        $this->assertSame('GHS', $courses[0]['display_currency']);
        $this->assertTrue($courses[0]['purchasable']);
    }

    // ── Owner payout setup ─────────────────────────────────────────────────

    public function test_an_owner_links_a_ghanaian_bank_and_the_academy_sells_in_cedis(): void
    {
        $tenant = $this->academy('tema');
        $token = $this->ownerToken($tenant);

        $page = $this->withToken($token)->getJson('/api/frontend/lms/owner/payment-settings')->assertOk();
        $page->assertJsonPath('link_provider', 'flutterwave')
            ->assertJsonPath('academy.currency', 'NGN')
            ->assertJsonPath('payment.configured', false);
        $this->assertContains('GH', array_column($page->json('countries'), 'code'));

        $this->withToken($token)->getJson('/api/frontend/lms/owner/payout-banks?country=GH')
            ->assertOk()->assertJsonPath('banks.0.name', 'GCB Bank');

        $this->withToken($token)->postJson('/api/frontend/lms/owner/payment-settings', [
            'country' => 'GH', 'bank_code' => 'GH190100', 'bank_name' => 'GCB Bank',
            'account_number' => '1234567890', 'account_name' => 'Kofi Mensah', 'phone' => '0240000000',
            'branch_code' => 'GH190101',
        ])->assertOk()->assertJsonPath('provider', 'flutterwave');

        $tenant->refresh();
        $this->assertSame('RS_NEW', $tenant->flutterwaveSubaccountId());
        $this->assertSame('GHS', $tenant->currency());
        $this->assertSame('GH', $tenant->payoutCountry());
        $this->assertSame('flutterwave', CoursePayments::gatewayFor($tenant));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::FLW . 'subaccounts'
            && $r['country'] === 'GH'
            && $r['account_bank'] === 'GH190100'
            && $r['meta'][0]['meta_name'] === 'branch_code');
    }

    public function test_uk_and_us_banks_need_their_extra_details(): void
    {
        $tenant = $this->academy('london');
        $token = $this->ownerToken($tenant);

        $this->withToken($token)->postJson('/api/frontend/lms/owner/payment-settings', [
            'country' => 'GB', 'account_number' => 'GB33BUKB20201555555555', 'account_name' => 'Ada Obi', 'phone' => '07000000000',
        ])->assertStatus(422)->assertJsonPath('message', 'Enter your bank’s SWIFT/BIC code.');

        $this->withToken($token)->postJson('/api/frontend/lms/owner/payment-settings', [
            'country' => 'US', 'bank_code' => '044', 'account_number' => '000123456789', 'account_name' => 'Ada Obi', 'phone' => '2025550100',
            'swift_code' => 'CHASUS33',
        ])->assertStatus(422)->assertJsonPath('message', 'Enter your routing number.');

        $this->withToken($token)->postJson('/api/frontend/lms/owner/payment-settings', [
            'country' => 'GB', 'account_number' => 'GB33 BUKB 2020 1555 5555 55', 'account_name' => 'Ada Obi', 'phone' => '07000000000',
            'swift_code' => 'bukbgb22',
        ])->assertOk();

        $this->assertSame('GBP', $tenant->fresh()->currency());
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::FLW . 'subaccounts'
            && $r['account_number'] === 'GB33BUKB20201555555555'
            && $r['meta'][0]['meta_value'] === 'BUKBGB22');
    }

    public function test_the_currency_is_fixed_once_students_have_paid(): void
    {
        $gh = $this->ghana();
        $token = $this->ownerToken($gh);
        $id = $this->register($gh, $this->course($gh))->json('registration_id');
        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])->assertOk();
        $payment = Payment::withoutGlobalScope(TenantScope::class)->where('registration_id', $id)->sole();
        $this->transactions[$payment->reference] = $this->txn($payment->reference, 1200, 'GHS');
        $this->getJson('/api/frontend/paystack/verify?reference=' . $payment->reference)->assertJsonPath('status', 'success');

        $this->withToken($token)->getJson('/api/frontend/lms/owner/payment-settings')
            ->assertJsonPath('academy.currency_locked', true);
        $this->withToken($token)->postJson('/api/frontend/lms/owner/payment-settings', [
            'country' => 'KE', 'bank_code' => '01', 'account_number' => '0123456789', 'account_name' => 'Kofi Mensah', 'phone' => '0700000000',
        ])->assertStatus(422);
        $this->assertSame('GHS', $gh->fresh()->currency());
    }

    public function test_with_flutterwave_on_an_old_paystack_link_takes_no_payments_until_relinked(): void
    {
        $legacy = $this->academy('lagos', ['paystack' => ['subaccount_code' => 'ACCT_old', 'bank_code' => '058', 'account_number' => '0123456789']]);
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        // Everything goes through Flutterwave: no Paystack fallback, no selling.
        $this->assertNull(CoursePayments::gatewayFor($legacy));
        $this->assertFalse($legacy->payoutConfigured());

        $this->withToken($this->ownerToken($legacy))->getJson('/api/frontend/lms/owner/payment-settings')
            ->assertOk()
            ->assertJsonPath('payment.needs_relink', true);
    }

    public function test_clearing_paystack_links_archives_them_and_asks_owners_to_relink(): void
    {
        $legacy = $this->academy('lagos', ['paystack' => ['subaccount_code' => 'ACCT_old', 'bank_code' => '058', 'account_number' => '0123456789']]);
        $gh = $this->ghana(); // already on Flutterwave: untouched

        $this->artisan('lms:clear-paystack-payouts', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('ACCT_old', data_get($legacy->fresh()->settings, 'paystack.subaccount_code'));

        $this->artisan('lms:clear-paystack-payouts')->assertSuccessful();

        $settings = $legacy->fresh()->settings;
        $this->assertNull(data_get($settings, 'paystack'));
        $this->assertSame('ACCT_old', data_get($settings, 'paystack_archived.subaccount_code'));
        $this->assertTrue((bool) data_get($settings, 'payout_relink_required'));
        $this->assertSame('RS_GH', $gh->fresh()->flutterwaveSubaccountId());

        // The owner is told (in-app, and emailed by the sweep) where to fix it.
        $note = \App\Models\LmsOwnerNotification::withoutGlobalScope(TenantScope::class)->where('tenant_id', $legacy->id)->sole();
        $this->assertSame('payout_relink', $note->type);
        $this->assertStringEndsWith('/lms/admin/profile?tab=payment', \App\Support\NotificationLinks::pathFor('owner', 'payout'));

        // The payout page keeps asking until they link again.
        $this->withToken($this->ownerToken($legacy))->getJson('/api/frontend/lms/owner/payment-settings')
            ->assertJsonPath('payment.needs_relink', true);
    }

    public function test_disconnecting_removes_the_flutterwave_subaccount(): void
    {
        $gh = $this->ghana();

        $this->withToken($this->ownerToken($gh))->postJson('/api/frontend/lms/owner/payment-settings', ['disconnect' => true])
            ->assertOk()->assertJsonPath('configured', false);

        $this->assertNull($gh->fresh()->flutterwaveSubaccountId());
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->url() === self::FLW . 'subaccounts/77');
    }
}
