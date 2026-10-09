<?php

namespace Tests\Feature;

use App\Http\Controllers\LmsIntakeController;
use App\Models\LmsCourse;
use App\Models\LmsOwnerNotification;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * One course per student per academy, and no paying twice (EnrollmentGuard).
 */
class DuplicateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Basic plan so the Free 1-student cap never interferes; a linked bank
        // so paid checkout is allowed.
        $this->acme = $this->academy('acme');

        $this->mock(PaystackService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('initializeTransaction')->andReturn([
                'data' => ['authorization_url' => 'https://paystack.test/pay'],
            ]);
        });
    }

    private function academy(string $slug): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug), 'slug' => $slug, 'plan' => 'basic',
            'subscription_status' => 'active', 'current_period_end' => now()->addMonth(),
            'settings' => ['paystack' => ['subaccount_code' => 'ACCT_' . $slug]],
        ]);
    }

    private function course(Tenant $tenant, string $title, array $extra = []): LmsCourse
    {
        app()->instance('currentTenant', $tenant);
        try {
            return LmsCourse::create(['title' => $title, 'price' => 20000, 'max_students' => 20, 'is_active' => true] + $extra);
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    private function register(LmsCourse $course, string $slug = 'acme', string $email = 'ada@learner.test')
    {
        return $this->postJson('/api/frontend/training/register', [
            'first_name' => 'Ada', 'last_name' => 'Learner', 'date_of_birth' => '2000-01-01',
            'qualification_level' => 'SSCE', 'phone_number' => '08000000000', 'email' => $email,
            'whatsapp' => '08000000000', 'course_id' => $course->id, 'learning_mode' => 'live',
            'institute_slug' => $slug,
        ]);
    }

    /** Register, start checkout and confirm payment, like a real purchase. */
    private function buy(LmsCourse $course, string $email = 'ada@learner.test'): int
    {
        $id = $this->register($course, 'acme', $email)->assertCreated()->json('registration_id');
        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])->assertOk();

        $this->confirm($id);

        return $id;
    }

    private function confirm(int $registrationId): Payment
    {
        $payment = Payment::withoutGlobalScope(TenantScope::class)
            ->where('registration_id', $registrationId)->latest('id')->firstOrFail();

        app(LmsIntakeController::class)->confirmFromWebhook($payment, [
            'amount' => (int) round((float) $payment->amount * 100),
            'currency' => 'NGN',
        ]);

        return $payment->fresh();
    }

    public function test_registering_twice_before_paying_reuses_one_registration(): void
    {
        $course = $this->course($this->acme, 'Web Dev');

        $first = $this->register($course)->assertCreated()->json('registration_id');
        $second = $this->register($course)->assertCreated()->json('registration_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, TrainingRegistration::withoutGlobalScope(TenantScope::class)->where('email', 'ada@learner.test')->count());
    }

    public function test_an_enrolled_student_cannot_register_for_the_same_course_again(): void
    {
        $course = $this->course($this->acme, 'Web Dev');
        $this->buy($course);

        $this->register($course)
            ->assertStatus(409)
            ->assertJsonPath('already_enrolled', true);

        // Case and spacing in the email make no difference.
        $this->register($course, 'acme', '  ADA@Learner.test ')->assertStatus(409);
    }

    public function test_a_monthly_student_is_sent_to_billing_instead(): void
    {
        $course = $this->course($this->acme, 'Coding Club', ['billing_type' => 'monthly']);
        $this->buy($course);

        $message = $this->register($course)->assertStatus(409)->json('message');
        $this->assertStringContainsString('Billing', $message);
    }

    public function test_a_logged_in_student_sees_what_they_are_enrolled_in(): void
    {
        // Feeds the public pages' "Enrolled" badge / "Go to my course" panel.
        $course = $this->course($this->acme, 'Web Dev');
        $this->buy($course);

        $student = \App\Models\LmsStudent::withoutGlobalScope(TenantScope::class)->where('email', 'ada@learner.test')->firstOrFail();
        $token = \Illuminate\Support\Str::random(80);
        app()->instance('currentTenant', $this->acme);
        \App\Models\LmsSession::create(['role' => 'student', 'user_id' => $student->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        app()->forgetInstance('currentTenant');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/frontend/lms/me')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'acme')
            ->assertJsonPath('enrolment.course_id', $course->id)
            ->assertJsonPath('enrolment.course_title', 'Web Dev');
    }

    private function check(LmsCourse $course, string $email = 'ada@learner.test', string $slug = 'acme')
    {
        return $this->postJson('/api/frontend/training/check-enrolment', [
            'email' => $email, 'course_id' => $course->id, 'institute_slug' => $slug,
        ]);
    }

    public function test_the_early_email_check_gives_the_same_answer_as_submitting(): void
    {
        $webDev = $this->course($this->acme, 'Web Dev');
        $design = $this->course($this->acme, 'Design');

        // Nobody yet.
        $this->check($webDev)->assertOk()->assertJsonPath('status', 'ok');

        $this->buy($webDev);

        $this->check($webDev)->assertOk()
            ->assertJsonPath('status', 'already_enrolled')
            ->assertJsonPath('portal_path', '/lms/app');
        $this->check($design)->assertOk()
            ->assertJsonPath('status', 'enrolled_elsewhere');
        $this->check($webDev, 'someone.else@learner.test')->assertOk()->assertJsonPath('status', 'ok');

        // Another academy knows nothing about this academy's students.
        $other = $this->academy('beta');
        $this->check($this->course($other, 'Data'), 'ada@learner.test', 'beta')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_a_monthly_student_is_sent_to_billing_after_logging_in(): void
    {
        $course = $this->course($this->acme, 'Coding Club', ['billing_type' => 'monthly']);
        $this->buy($course);

        $this->check($course)->assertJsonPath('portal_path', '/lms/app/billing');
    }

    public function test_the_early_email_check_is_rate_limited(): void
    {
        $course = $this->course($this->acme, 'Web Dev');

        foreach (range(1, 15) as $i) {
            $this->check($course, "probe{$i}@learner.test")->assertOk();
        }
        $this->check($course, 'probe16@learner.test')->assertStatus(429);
    }

    public function test_one_course_per_academy(): void
    {
        $webDev = $this->course($this->acme, 'Web Dev');
        $design = $this->course($this->acme, 'Design');
        $this->buy($webDev);

        $response = $this->register($design)->assertStatus(409)->assertJsonPath('enrolled_elsewhere', true);
        $this->assertStringContainsString('Web Dev', $response->json('message'));
    }

    public function test_the_same_email_can_enrol_at_another_academy(): void
    {
        $this->buy($this->course($this->acme, 'Web Dev'));

        $other = $this->academy('beta');
        $this->register($this->course($other, 'Data Science'), 'beta')->assertCreated();
    }

    public function test_a_second_checkout_is_refused_once_one_course_is_paid(): void
    {
        // Two tabs: registered for both courses before paying either.
        $webDev = $this->course($this->acme, 'Web Dev');
        $design = $this->course($this->acme, 'Design');
        $a = $this->register($webDev)->assertCreated()->json('registration_id');
        $b = $this->register($design)->assertCreated()->json('registration_id');

        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $a])->assertOk();
        $this->confirm($a);

        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $b])
            ->assertStatus(409)
            ->assertJsonPath('enrolled_elsewhere', true);
    }

    public function test_a_payment_that_slips_through_is_held_for_refund_not_enrolled_twice(): void
    {
        // Both checkouts were opened before either completed, so both reached
        // Paystack. The second one to confirm must not enrol again.
        $course = $this->course($this->acme, 'Web Dev');
        $id = $this->register($course)->assertCreated()->json('registration_id');
        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])->assertOk();
        $this->postJson('/api/frontend/paystack/initialize', ['registration_id' => $id])->assertOk();

        $payments = Payment::withoutGlobalScope(TenantScope::class)->where('registration_id', $id)->orderBy('id')->get();
        $this->assertCount(2, $payments);

        foreach ($payments as $payment) {
            app(LmsIntakeController::class)->confirmFromWebhook($payment, ['amount' => 2000000, 'currency' => 'NGN']);
        }

        $this->assertSame('success', $payments[0]->fresh()->status);
        $this->assertSame('review', $payments[1]->fresh()->status);
        $this->assertSame(1, (int) LmsCourse::withoutGlobalScope(TenantScope::class)->find($course->id)->registered_count);
        $this->assertTrue(
            LmsOwnerNotification::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $this->acme->id)->where('type', 'payment_duplicate')->exists()
        );
    }
}
