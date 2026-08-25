<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-institute public storefront: each institute's own mini-site
 * (/i/{slug}) and the apex primary page (/institute/primary) must surface ONLY
 * that institute's active courses + its branding — the tenant is resolved from
 * the URL, not a header. Self-registration from a storefront must stamp the
 * registration and its payment with that institute's tenant_id. Guards the leak
 * fix where the old, unscoped catalog returned every tenant's courses at once.
 */
class PublicInstituteTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
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

    public function test_storefront_shows_only_that_institutes_active_courses(): void
    {
        $alpha = $this->makeTenant('alpha');
        $beta = $this->makeTenant('beta');

        $this->asTenant($alpha, function () {
            LmsCourse::create(['title' => 'Alpha Live', 'slug' => 'alpha-live', 'is_active' => true]);
            LmsCourse::create(['title' => 'Alpha Archived', 'slug' => 'alpha-archived', 'is_active' => false]);
        });
        $this->asTenant($beta, fn () => LmsCourse::create(['title' => 'Beta Only', 'slug' => 'beta-only', 'is_active' => true]));

        // No tenant is bound from the test — the controller binds it from the
        // URL slug alone (a server-rendered fetch carries no tenant header).
        $response = $this->getJson('/api/frontend/i/alpha');

        $response->assertOk()
            ->assertJsonPath('institute.slug', 'alpha')
            ->assertJsonPath('institute.name', 'Alpha')
            ->assertJsonCount(1, 'courses')            // only the ACTIVE course
            ->assertJsonPath('courses.0.slug', 'alpha-live')
            ->assertJsonStructure(['branding' => ['primary_color', 'secondary_color']]);

        // Neither the archived alpha course nor any beta course leaks in.
        $titles = collect($response->json('courses'))->pluck('title');
        $this->assertFalse($titles->contains('Alpha Archived'));
        $this->assertFalse($titles->contains('Beta Only'));
    }

    public function test_unknown_institute_slug_returns_404(): void
    {
        $this->getJson('/api/frontend/i/does-not-exist')->assertNotFound();
    }

    public function test_course_detail_is_scoped_to_the_institute(): void
    {
        $alpha = $this->makeTenant('alpha');
        $beta = $this->makeTenant('beta');

        $this->asTenant($alpha, fn () => LmsCourse::create(['title' => 'Alpha Course', 'slug' => 'alpha-course', 'is_active' => true]));
        $this->asTenant($beta, fn () => LmsCourse::create(['title' => 'Beta Course', 'slug' => 'beta-course', 'is_active' => true]));

        $this->getJson('/api/frontend/i/alpha/courses/alpha-course')
            ->assertOk()
            ->assertJsonPath('institute.slug', 'alpha')
            ->assertJsonPath('course.slug', 'alpha-course');

        // Beta's course must not be reachable under alpha's storefront.
        $this->getJson('/api/frontend/i/alpha/courses/beta-course')->assertNotFound();
    }

    public function test_storefront_returns_the_institutes_saved_public_profile(): void
    {
        $alpha = $this->makeTenant('alpha');
        $this->asTenant($alpha, fn () => LmsCourse::create(['title' => 'Alpha Course', 'slug' => 'alpha-course', 'is_active' => true]));

        // Stored exactly as OwnerAdminController::updateProfile writes it.
        $alpha->update(['settings' => ['profile' => [
            'tagline' => 'Learn by doing',
            'about' => 'We train builders.',
            'cover_url' => 'https://cdn.test/cover.jpg',
            'contact' => ['email' => 'hi@alpha.test', 'phone' => '0800', 'whatsapp' => '0801', 'address' => 'Lagos'],
            'socials' => ['website' => 'https://alpha.test', 'facebook' => 'https://fb.com/alpha', 'instagram' => null, 'twitter' => null, 'linkedin' => 'https://linkedin.com/alpha'],
        ]]]);

        $this->getJson('/api/frontend/i/alpha')
            ->assertOk()
            ->assertJsonPath('profile.tagline', 'Learn by doing')
            ->assertJsonPath('profile.about', 'We train builders.')
            ->assertJsonPath('profile.cover_url', 'https://cdn.test/cover.jpg')
            ->assertJsonPath('profile.contact.email', 'hi@alpha.test')
            ->assertJsonPath('profile.contact.address', 'Lagos')
            ->assertJsonPath('profile.socials.website', 'https://alpha.test')
            ->assertJsonPath('profile.socials.linkedin', 'https://linkedin.com/alpha')
            ->assertJsonPath('profile.socials.twitter', null);

        // The course-detail response carries the same profile for the shared footer.
        $this->getJson('/api/frontend/i/alpha/courses/alpha-course')
            ->assertOk()
            ->assertJsonPath('profile.contact.email', 'hi@alpha.test');
    }

    public function test_storefront_returns_a_fully_keyed_null_profile_when_unset(): void
    {
        $alpha = $this->makeTenant('alpha');
        $this->asTenant($alpha, fn () => LmsCourse::create(['title' => 'Alpha Course', 'slug' => 'alpha-course', 'is_active' => true]));

        // An institute that never touched its public page still gets the full,
        // null-valued shape (so the frontend can read profile.contact.email etc.
        // without guards).
        $this->getJson('/api/frontend/i/alpha')
            ->assertOk()
            ->assertJsonPath('profile.tagline', null)
            ->assertJsonPath('profile.cover_url', null)
            ->assertJsonPath('profile.contact.email', null)
            ->assertJsonPath('profile.socials.facebook', null)
            ->assertJsonStructure([
                'profile' => [
                    'tagline', 'about', 'cover_url',
                    'contact' => ['email', 'phone', 'whatsapp', 'address'],
                    'socials' => ['website', 'facebook', 'instagram', 'twitter', 'linkedin'],
                ],
            ]);
    }

    public function test_primary_storefront_shows_only_the_primary_institute(): void
    {
        config(['saas.primary_slug' => 'jorsas']);
        $primary = $this->makeTenant('jorsas');
        $other = $this->makeTenant('beta');

        $this->asTenant($primary, fn () => LmsCourse::create(['title' => 'Primary Course', 'slug' => 'primary-course', 'is_active' => true]));
        $this->asTenant($other, fn () => LmsCourse::create(['title' => 'Other Course', 'slug' => 'other-course', 'is_active' => true]));

        $this->getJson('/api/frontend/institute/primary')
            ->assertOk()
            ->assertJsonPath('institute.slug', 'jorsas')
            ->assertJsonCount(1, 'courses')
            ->assertJsonPath('courses.0.slug', 'primary-course');
    }

    public function test_registration_from_a_storefront_stamps_the_institute_tenant(): void
    {
        $alpha = $this->makeTenant('alpha');
        $this->makeTenant('beta'); // a second institute the registration must NOT be attributed to

        // A paid course, so initializePayment takes the Paystack branch and
        // creates a Payment row (rather than the free path, which is covered by
        // the intake tests and creates a student instead of a payment).
        $course = $this->asTenant($alpha, fn () => LmsCourse::create([
            'title' => 'Paid Alpha Course',
            'slug' => 'paid-alpha',
            'price' => 50000,
            'is_active' => true,
        ]));

        $register = $this->postJson('/api/frontend/training/register', [
            'first_name' => 'Sam',
            'last_name' => 'Learner',
            'date_of_birth' => '2000-01-01',
            'qualification_level' => 'SSCE',
            'phone_number' => '08000000000',
            'email' => 'sam@learner.test',
            'whatsapp' => '08000000000',
            'course_id' => $course->id,
            'learning_mode' => 'live',
            'institute_slug' => 'alpha',
        ]);

        $register->assertCreated();
        $registrationId = $register->json('registration_id');

        // The registration row carries alpha's tenant_id — not beta's, not the
        // primary's — because the storefront sent institute_slug explicitly.
        $registration = TrainingRegistration::withoutGlobalScope(TenantScope::class)->findOrFail($registrationId);
        $this->assertSame($alpha->id, $registration->tenant_id);

        // Fake the gateway so initializePayment creates the (pending) Payment
        // and returns an authorization URL, without any real HTTP call.
        $this->mock(PaystackService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->andReturn([
                'data' => ['authorization_url' => 'https://paystack.test/pay/xyz'],
            ]);
        });

        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $registrationId])
            ->assertOk()
            ->assertJsonPath('authorization_url', 'https://paystack.test/pay/xyz');

        // The Payment inherits the same institute tenant from the registration.
        $payment = Payment::withoutGlobalScope(TenantScope::class)
            ->where('registration_id', $registrationId)
            ->firstOrFail();

        $this->assertSame($alpha->id, $payment->tenant_id);
        $this->assertSame('pending', $payment->status);
    }

    /**
     * Register + start payment on a storefront, faking Paystack, and return the
     * callback_url the controller handed the gateway. This is what Paystack
     * redirects the payer back to after checkout.
     */
    private function callbackUrlForStorefrontPayment(string $slug): string
    {
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        $course = $this->asTenant($tenant, fn () => LmsCourse::create([
            'title' => 'Paid Course',
            'slug' => 'paid-' . $slug,
            'price' => 50000,
            'is_active' => true,
        ]));

        $register = $this->postJson('/api/frontend/training/register', [
            'first_name' => 'Sam',
            'last_name' => 'Learner',
            'date_of_birth' => '2000-01-01',
            'qualification_level' => 'SSCE',
            'phone_number' => '08000000000',
            'email' => 'sam@learner.test',
            'whatsapp' => '08000000000',
            'course_id' => $course->id,
            'learning_mode' => 'live',
            'institute_slug' => $slug,
        ]);
        $register->assertCreated();

        $captured = null;
        $this->mock(PaystackService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->andReturnUsing(
                function ($email, $amount, $reference, $metadata = [], $callbackUrl = null) use (&$captured) {
                    $captured = $callbackUrl;

                    return ['data' => ['authorization_url' => 'https://paystack.test/pay/xyz']];
                }
            );
        });

        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $register->json('registration_id')])
            ->assertOk();

        $this->assertNotNull($captured, 'initializeTransaction was never called.');

        return $captured;
    }

    public function test_paid_storefront_payment_returns_to_the_institutes_own_verify_page(): void
    {
        config(['saas.primary_slug' => 'jorsas']);
        $this->makeTenant('jorsas'); // the primary — the callback must NOT point here
        $this->makeTenant('acme');   // a non-primary institute storefront

        $callback = $this->callbackUrlForStorefrontPayment('acme');

        // The payer is sent back into acme's branded mini-site, not the JIT apex
        // "/institute/verify" page (that was the bug: JIT chrome after paying on
        // another institute's storefront).
        $this->assertStringContainsString('/i/acme/verify?reference=', $callback);
        $this->assertStringNotContainsString('/institute/verify', $callback);
    }

    public function test_primary_storefront_payment_keeps_the_apex_verify_page(): void
    {
        config(['saas.primary_slug' => 'jorsas']);
        $this->makeTenant('jorsas');

        $callback = $this->callbackUrlForStorefrontPayment('jorsas');

        // The primary institute (JIT) keeps its existing apex verify page — it has
        // no separate /i/{slug} mini-site front door in the normal flow.
        $this->assertStringContainsString('/institute/verify?reference=', $callback);
        $this->assertStringNotContainsString('/i/jorsas/verify', $callback);
    }

    /** Store $primaryColor as this institute's white-label branding. */
    private function brandInstitute(Tenant $tenant, string $primaryColor, string $font = 'default'): void
    {
        $tenant->update(['settings' => ['branding' => [
            'primary_color' => $primaryColor,
            'font_family' => $font,
        ]]]);
    }

    /** A tenant-stamped registration carrying $token (the invite/setup link token). */
    private function inviteRegistration(Tenant $tenant, string $token): TrainingRegistration
    {
        return $this->asTenant($tenant, fn () => TrainingRegistration::create([
            'first_name' => 'Sam',
            'last_name' => 'Learner',
            'date_of_birth' => '2000-01-01',
            'qualification_level' => 'SSCE',
            'phone_number' => '08000000000',
            'email' => 'sam+' . $token . '@learner.test',
            'whatsapp' => '08000000000',
            'course_name' => 'Some Course',
            'learning_mode' => 'live',
            'invite_token' => $token,
        ]));
    }

    /**
     * The UNAUTHENTICATED pages (student/staff password setup, login, reset) theme
     * their shell from GET /api/frontend/lms/branding/public BEFORE any portal
     * session exists. The institute is resolved from the setup/invite token in the
     * URL — so a JesamFC student setting a password sees JesamFC's colours/font,
     * not the primary (JIT) default theme (the reported bug).
     */
    public function test_public_branding_resolves_the_institute_from_the_invite_token(): void
    {
        config(['saas.primary_slug' => 'jorsas']);
        $primary = $this->makeTenant('jorsas');
        $acme = $this->makeTenant('acme');
        // Distinct palettes so the assertion proves token-resolution, not the
        // ResolveTenant legacy fallback (which would bind the primary).
        $this->brandInstitute($primary, '#111111', 'default');
        $this->brandInstitute($acme, '#0a1b2c', 'serif');

        $registration = $this->inviteRegistration($acme, 'setup-token-xyz');
        $this->assertSame($acme->id, $registration->tenant_id);

        // No tenant header — only the ?token= the setup-password link carries.
        $this->getJson('/api/frontend/lms/branding/public?token=setup-token-xyz')
            ->assertOk()
            ->assertJsonPath('branding.primary_color', '#0a1b2c')
            ->assertJsonPath('branding.font_family', 'serif');
    }

    public function test_public_branding_resolves_the_institute_from_the_tenant_header(): void
    {
        config(['saas.primary_slug' => 'jorsas']);
        $primary = $this->makeTenant('jorsas');
        $acme = $this->makeTenant('acme');
        $this->brandInstitute($primary, '#111111');
        $this->brandInstitute($acme, '#0a1b2c');

        // No token — the login/forgot pages instead carry the subdomain/header
        // (the frontend sends X-Tenant-Slug). It must beat the primary fallback.
        $this->getJson('/api/frontend/lms/branding/public', ['X-Tenant-Slug' => 'acme'])
            ->assertOk()
            ->assertJsonPath('branding.primary_color', '#0a1b2c');
    }

    public function test_public_branding_falls_back_to_the_primary_institute(): void
    {
        config(['saas.primary_slug' => 'jorsas']);
        $primary = $this->makeTenant('jorsas');
        $other = $this->makeTenant('beta');
        $this->brandInstitute($primary, '#111111');
        $this->brandInstitute($other, '#0a1b2c');

        // Neither token nor header — a bare request must theme with the primary
        // (JIT) institute's palette, never a random other tenant's.
        $this->getJson('/api/frontend/lms/branding/public')
            ->assertOk()
            ->assertJsonPath('branding.primary_color', '#111111');
    }
}
