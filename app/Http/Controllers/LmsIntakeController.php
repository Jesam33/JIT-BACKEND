<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Lms\BaseLmsController;
use App\Mail\PaymentConfirmationMail;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\LmsCourse;
use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTrack;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TrainingRegistration;
use App\Scopes\TenantScope;
use App\Services\CourseBilling;
use App\Services\CoursePayments;
use App\Support\EnrollmentGuard;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LmsIntakeController extends BaseLmsController
{
    private function ensureIntakeEnabled(): void
    {
        // Read via config (bound in config/saas.php), NOT env() directly, so the
        // flag still resolves after `php artisan config:cache` on live. Reading
        // env() here returned false under cached config and 404'd course
        // registration + the whole intake/payment flow. Mirrors ensureLmsEnabled().
        if (! config('saas.training_feature_enabled')) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * Bind currentTenant from a resolved record's tenant_id. Payment
     * verification and the Paystack webhook arrive with no tenant header (and
     * the webhook has no session at all), so the payment row, found by its
     * globally-unique reference, is the authoritative proof of the tenant.
     */
    protected function bindTenantFromModel($model): void
    {
        $tenantId = $model->tenant_id ?? null;
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                app()->instance('currentTenant', $tenant);
            }
        }
    }

    public function courseCatalog(): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $courses = LmsCourse::query()
            ->where('is_active', true)
            ->withPrerecordedCounts()
            ->orderBy('title')
            ->get()
            ->map(fn (LmsCourse $course) => [
                'id' => $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
                'description' => $course->description,
                'price' => (float) $course->price,
                'billing_type' => $course->billing_type ?: LmsCourse::BILLING_ONE_TIME,
                'max_students' => $course->max_students,
                'registered_count' => $course->registered_count,
                'slots_remaining' => $course->slotsRemaining(),
                'is_full' => $course->isFull(),
                'is_live_available' => $course->is_live_available,
                // Content-gated like the storefront: the toggle alone is not
                // enough, the course must have a pre-recorded lesson behind it.
                'is_prerecorded_available' => $course->is_prerecorded_available && $course->hasPrerecordedContent(),
                'is_one_on_one_available' => $course->offersOneOnOne(app()->bound('currentTenant') ? app('currentTenant') : null),
                'one_on_one_price' => $course->one_on_one_price !== null ? (float) $course->one_on_one_price : null,
                'registration_open' => $course->registrationOpen(),
                'registration_closes_at' => $course->openCohort()?->registrationClosesAt(),
                'next_cohort_starts_at' => $course->tracks()->whereNotNull('start_date')->orderBy('start_date')->value('start_date'),
            ])
            ->values();

        return response()->json($courses);
    }

    public function courseDetail(string $slug): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $course = LmsCourse::query()
            ->withPrerecordedCounts()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        return response()->json([
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->title,
            'description' => $course->description,
            'requirements' => $course->requirements,
            'price' => (float) $course->price,
            'billing_type' => $course->billing_type ?: LmsCourse::BILLING_ONE_TIME,
            'max_students' => $course->max_students,
            'registered_count' => $course->registered_count,
            'slots_remaining' => $course->slotsRemaining(),
            'is_full' => $course->isFull(),
            'is_live_available' => $course->is_live_available,
            // Content-gated like the storefront (see LmsCourse::hasPrerecordedContent).
            'is_prerecorded_available' => $course->is_prerecorded_available && $course->hasPrerecordedContent(),
                'is_one_on_one_available' => $course->offersOneOnOne(app()->bound('currentTenant') ? app('currentTenant') : null),
                'one_on_one_price' => $course->one_on_one_price !== null ? (float) $course->one_on_one_price : null,
            'registration_open' => $course->registrationOpen(),
            'registration_closes_at' => $course->openCohort()?->registrationClosesAt(),
            'next_cohort_starts_at' => $course->tracks()->whereNotNull('start_date')->orderBy('start_date')->value('start_date'),
        ]);
    }

    /**
     * Early "are you already enrolled?" check for the storefront form, run as
     * soon as the visitor types their email, so they don't fill in the whole
     * form only to be refused at submit. Same answer register() would give
     * (EnrollmentGuard), and nothing more: it reveals no more than submitting
     * the form already does. Rate-limited in the route, since an open "is this
     * email enrolled?" lookup is otherwise an easy way to probe a roster.
     */
    public function checkEnrolment(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'course_id' => ['required', 'integer'],
            'institute_slug' => ['nullable', 'string', 'max:255'],
        ]);

        // Same tenant binding as register(): the explicit storefront slug wins.
        if (! empty($validated['institute_slug'])) {
            $tenant = Tenant::query()->where('slug', $validated['institute_slug'])->first();
            if ($tenant) {
                app()->instance('currentTenant', $tenant);
            }
        }

        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        // Only courses of this academy (tenant-scoped); anything else is "fine",
        // and register() does the real validation.
        $course = LmsCourse::query()->find($validated['course_id']);
        if (! $course) {
            return response()->json(['status' => 'ok']);
        }

        $conflict = EnrollmentGuard::conflict($tenant, $validated['email'], (int) $course->id);

        return response()->json($conflict
            ? ['status' => isset($conflict['already_enrolled']) ? 'already_enrolled' : 'enrolled_elsewhere'] + $conflict
            : ['status' => 'ok']);
    }

    public function register(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            // No longer asked on the course form; kept accepted for older clients.
            'date_of_birth' => ['nullable', 'date'],
            'qualification_level' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'],
            'whatsapp' => ['required', 'string', 'max:40'],
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'learning_mode' => ['required', 'in:live,pre_recorded,one_on_one'],
            'referral_code' => ['nullable', 'string', 'max:20'],
            'institute_slug' => ['nullable', 'string', 'max:255'],
            // Visitor country hint (ISO alpha-2), forwarded by the storefront so
            // the charge currency can be resolved server-side. Never trusted for
            // the AMOUNT (always the course's own price); at most it selects which
            // supported currency is charged. Absent → charged NGN.
            'country' => ['nullable', 'string', 'max:2'],
        ]);

        // A student registering from an institute's public storefront
        // (/i/{slug}) sends its slug explicitly. Bind that tenant as
        // authoritative BEFORE the course lookup and create, so the course
        // resolves within the institute and the registration row is stamped
        // with the right tenant_id. On a path-based storefront the browser's
        // X-Tenant-Slug header falls back to the primary tenant, so it cannot
        // be trusted here; an explicit slug overrides it.
        if (! empty($validated['institute_slug'])) {
            $tenant = Tenant::query()->where('slug', $validated['institute_slug'])->first();
            if ($tenant) {
                app()->instance('currentTenant', $tenant);
            }
        }

        // "Stop selling": a deactivated academy takes no new registrations. The
        // storefront shows its offline page, but that is only the shopfront, so
        // the rule is enforced here too and a form posted (or replayed) directly
        // cannot slip a student past it. Checked before the course lookup so the
        // refusal never depends on which course was named.
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        if ($tenant && ! $tenant->acceptsNewStudents()) {
            return response()->json([
                'message' => 'This academy isn’t accepting new student registrations right now. Please check back soon.',
                'institute_inactive' => true,
            ], 422);
        }

        $course = LmsCourse::query()->findOrFail($validated['course_id']);

        // One course per student per academy: an email already enrolled here
        // (in this course or another) is refused before anything is created or
        // charged. Checked before "full" so an enrolled student is told the real
        // reason. See EnrollmentGuard.
        if ($conflict = EnrollmentGuard::conflict($tenant, $validated['email'], (int) $course->id)) {
            return response()->json($conflict, 409);
        }

        if ($course->isFull()) {
            return response()->json([
                'message' => 'This course is full. Please join the waitlist.',
                'is_full' => true,
            ], 422);
        }

        // Cohort registration cutoff (the hard guarantee behind the storefront's
        // closed state): a course whose cohorts all have closed registration
        // windows takes no new students, even if the form is submitted directly.
        // LmsCourse::registrationOpen() keeps a no-cohort course open.
        if (! $course->registrationOpen()) {
            return response()->json([
                'message' => 'Registration for this course has closed. Please check back for the next cohort.',
                'registration_closed' => true,
            ], 422);
        }

        // Pre-recorded video is a Pro+ entitlement, and the per-course toggle is
        // only forced off when the course is SAVED on a plan without it. A course
        // switched on while the academy was on Pro therefore keeps the flag after
        // a downgrade to Basic, so the tier is re-checked here on every sale
        // instead of trusted from a row that may predate the plan.
        if ($validated['learning_mode'] === 'pre_recorded'
            && $tenant !== null
            && ! $tenant->planFeature('pre_recorded_video')) {
            return response()->json([
                'message' => 'Pre-recorded lessons aren’t offered by this academy right now. Please choose the live option or check back soon.',
                'prerecorded_unavailable' => true,
            ], 422);
        }

        // Pre-recorded is a real delivery mode only when the course actually has
        // a pre-recorded lesson behind it. The storefront hides the option in
        // that state, but that is only the shopfront: a replayed form, a stale
        // page, or a course whose last video was just deleted all reach here, and
        // selling a mode with nothing to deliver takes the student's money for an
        // empty materials page. Also enforces the per-course toggle itself, which
        // this path previously trusted the client about.
        if ($validated['learning_mode'] === 'pre_recorded'
            && (! $course->is_prerecorded_available || ! $course->hasPrerecordedContent())) {
            return response()->json([
                'message' => 'Pre-recorded lessons for this course aren’t available yet. Please choose the live option or check back soon.',
                'prerecorded_unavailable' => true,
            ], 422);
        }

        // One-on-one: only when the course offers it AND the academy's plan
        // includes it (Basic and up), re-checked here on every sale.
        if ($validated['learning_mode'] === LmsStudent::MODE_ONE_ON_ONE && ! $course->offersOneOnOne($tenant)) {
            return response()->json([
                'message' => 'One-on-one classes aren’t offered for this course right now. Please choose another option.',
                'one_on_one_unavailable' => true,
            ], 422);
        }

        // Plan student cap. Money-safe by design: this never throws (a self-enrolling
        // visitor can't upgrade the institute's plan) and never blocks an existing
        // student re-enrolling in another course, only a brand-new student email at
        // an institute that has hit its seat cap is turned away, with a neutral
        // message. The primary institute is unlimited, so this is a no-op there.
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        if ($tenant && \App\Support\PlanGate::studentLimitReached($tenant, $validated['email']) !== null) {
            return response()->json([
                'message' => 'This institute isn’t taking new student registrations right now. Please check back soon.',
                'institute_full' => true,
            ], 422);
        }

        $referredByAgentId = null;
        // Pre-recorded (on-demand) is cheaper when the course sets a distinct
        // prerecorded_price; otherwise both modes charge the live price. This base
        // amount is what the referral discount + currency freeze below operate on,
        // and what ultimately settles to Paystack/commissions via course_price.
        // Live, pre-recorded or one-on-one, each at its own price (LmsCourse::priceFor).
        $price = $course->priceFor($validated['learning_mode']);
        if (! empty($validated['referral_code'])) {
            $agent = \App\Models\Agent::where('referral_code', $validated['referral_code'])
                ->where('status', 'approved')
                ->first();
            if ($agent) {
                $referredByAgentId = $agent->id;
                $price = round($price * 0.95, 2);
            }
        }

        // Freeze the CHARGE currency + amount: the academy's own currency, at the
        // price the owner set in it. No conversion: a Ghanaian academy sells in
        // cedis and is paid in cedis; the student's card handles their side.
        $chargeCurrency = $tenant ? $tenant->currency() : 'NGN';
        $chargeAmount = $price;

        $attributes = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'qualification_level' => $validated['qualification_level'],
            'phone_number' => $validated['phone_number'],
            'email' => $validated['email'],
            'whatsapp' => $validated['whatsapp'],
            'course_id' => $course->id,
            'course_name' => $course->title,
            'learning_mode' => $validated['learning_mode'],
            'course_price' => $chargeAmount,
            'charge_currency' => $chargeCurrency,
            'status' => 'pending',
            'referred_by_agent_id' => $referredByAgentId,
        ];

        // A second attempt at the same course before paying (abandoned checkout,
        // a second tab, a refresh) reuses the unpaid registration instead of
        // piling up duplicates; it is refreshed with this attempt's details.
        $registration = EnrollmentGuard::pendingRegistration($tenant, $validated['email'], (int) $course->id);
        if ($registration) {
            $registration->update($attributes + [
                // Re-freeze from the course as it is now (booted() only runs on create).
                'billing_type' => $chargeAmount > 0 && $course->isMonthly()
                    ? LmsCourse::BILLING_MONTHLY
                    : LmsCourse::BILLING_ONE_TIME,
            ]);
        } else {
            $registration = TrainingRegistration::query()->create($attributes);
        }

        return response()->json([
            'message' => 'Registration created. Proceed to payment.',
            'registration_id' => $registration->id,
            'course' => [
                'title' => $course->title,
                'price' => $chargeAmount,
                'charge_currency' => $chargeCurrency,
                'billing_type' => $registration->billing_type,
            ],
        ], 201);
    }

    public function initializePayment(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $validated = $request->validate([
            'registration_id' => ['required', 'integer', 'exists:training_registrations,id'],
        ]);

        $registration = TrainingRegistration::query()
            ->withoutGlobalScope(TenantScope::class)
            ->findOrFail($validated['registration_id']);

        // The registration row (stamped when it was created) is the source of
        // truth for the tenant here. This call can arrive carrying the primary
        // tenant's header from a /i/{slug} storefront, so bind from the row
        // instead, the Payment created below (and, on the free path, the
        // LmsStudent / commission rows) are then stamped for the right
        // institute. Mirrors the verify/webhook binding.
        $this->bindTenantFromModel($registration);

        if ($registration->status !== 'pending') {
            return response()->json(['message' => 'Payment already processed for this registration.'], 422);
        }

        // Re-checked here, not just at registration: two tabs (or a pending
        // registration for another course) can both reach checkout before either
        // is paid. Once one course is paid, the other checkout is refused.
        $boundTenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        if ($conflict = EnrollmentGuard::conflict($boundTenant, (string) $registration->email, (int) $registration->course_id)) {
            return response()->json($conflict, 409);
        }

        // Unlinked-institute guard (money safety). A non-primary institute that
        // hasn't linked its payout subaccount yet must not collect course fees
        // into the PLATFORM account. Block paid checkout until they link a bank;
        // the storefront reflects this upfront via `purchasable`, so this is the
        // defense-in-depth path (e.g. a stale page). Free courses (≤ 0) never reach
        // here, and the primary institute is exempt, it legitimately settles to
        // the platform account. Mirrors the primary-slug idiom used below at the
        // callback branch.
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        $gateway = CoursePayments::gatewayFor($tenant);

        if ((float) $registration->course_price > 0) {
            $isNonPrimary = $tenant && $tenant->slug && $tenant->slug !== config('saas.primary_slug', 'jorsas');
            if ($isNonPrimary && ! $gateway) {
                return response()->json([
                    'message' => 'This course isn’t open for purchase yet. The institute is finishing its payment setup.',
                ], 409);
            }
        }

        if ((float) $registration->course_price <= 0) {
            return $this->handleZeroPayment($registration);
        }

        $reference = 'JORSAS-' . Str::upper(Str::random(20));

        try {
            // No gateway configured on the platform at all (local dev): the
            // primary academy enrols without payment, as before.
            if (! $gateway) {
                return $this->handleZeroPayment($registration);
            }

            $frontendUrl = config('saas.frontend_url');

            if ($registration->registered_by_agent_id || $registration->referred_by_agent_id) {
                $callbackUrl = $frontendUrl . '/lms/agent/verify?reference=' . $reference;
            } else {
                // A registration stamped with a NON-primary institute came from
                // that institute's /i/{slug} storefront (register() bound the
                // tenant from institute_slug; bindTenantFromModel above re-bound
                // it here). Keep the Paystack callback inside that branded
                // mini-site so the "Verifying Payment" page and its nav stay on
                // the institute's site instead of bouncing to the JIT apex page.
                $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
                if ($tenant && $tenant->slug && $tenant->slug !== config('saas.primary_slug', 'jorsas')) {
                    $callbackUrl = $frontendUrl . '/i/' . $tenant->slug . '/verify?reference=' . $reference;
                } else {
                    $callbackUrl = $frontendUrl . '/institute/verify?reference=' . $reference;
                }
            }

            // The currency this registration was frozen to charge in: the
            // academy's own currency.
            $chargeCurrency = strtoupper((string) ($registration->charge_currency ?: 'NGN'));

            // The platform service charge on this payment (null when the money
            // settles to the platform account anyway, i.e. no subaccount).
            $charge = CourseBilling::serviceCharge($tenant, (float) $registration->course_price);

            $payment = Payment::query()->create([
                'registration_id' => $registration->id,
                'reference' => $reference,
                'amount' => $registration->course_price,
                'currency' => $chargeCurrency,
                'status' => 'pending',
                'gateway' => $gateway,
                'kind' => Payment::KIND_INITIAL,
                // 0 when unsplit (settles to the platform account): nothing held back.
                'platform_fee' => $charge['fee'] ?? 0,
                'academy_amount' => $charge['academy'],
            ]);

            // Flutterwave (or a pre-move Paystack link) splits the payment: the
            // academy's share to its bank, the service charge to the platform.
            $checkout = CoursePayments::start(
                $payment,
                $tenant,
                (string) $registration->email,
                trim($registration->first_name . ' ' . $registration->last_name),
                $callbackUrl,
                ['registration_id' => $registration->id, 'course_name' => $registration->course_name],
                $charge['fee'],
                (string) $registration->course_name
            );

            if (! $checkout['url']) {
                $payment->update(['status' => 'failed']);

                return response()->json(['message' => $checkout['message'] ?: 'Could not initialize payment. Please try again.'], 502);
            }

            return response()->json([
                'authorization_url' => $checkout['url'],
                'reference' => $reference,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Course payment initialization failed', [
                'error' => $exception->getMessage(),
                'registration_id' => $registration->id,
            ]);

            return response()->json(['message' => 'Could not initialize payment. Please try again.'], 500);
        }
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $validated = $request->validate([
            'reference' => ['required', 'string'],
        ]);

        $payment = Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('reference', $validated['reference'])
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'Payment reference not found.'], 404);
        }

        if ($payment->status === 'success') {
            return response()->json(['message' => 'Payment already verified.', 'status' => 'success']);
        }

        try {
            // Asked of the gateway that took this payment (payments.gateway).
            $data = CoursePayments::verify($payment);

            if (($data['status'] ?? '') === 'success') {
                return $this->completePayment($payment, $data);
            }

            return response()->json([
                'message' => 'Payment not yet confirmed.',
                'status' => $data['status'] ?? 'unknown',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Course payment verification failed', [
                'error' => $exception->getMessage(),
                'reference' => $validated['reference'],
            ]);

            return response()->json(['message' => 'Could not verify payment.'], 500);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        $signature = $request->header('x-paystack-signature');

        $secret = (string) config('services.paystack.secret_key', '');
        $payload = $request->getContent();

        if ($signature !== hash_hmac('sha512', $payload, $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = $request->input('event');

        if ($event === 'charge.success') {
            $reference = $request->input('data.reference');
            $payment = Payment::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('reference', $reference)
                ->first();

            if ($payment && $payment->status !== 'success') {
                $this->completePayment($payment, (array) $request->input('data'));
            }
        }

        return response()->json(['message' => 'Webhook received.']);
    }

    /**
     * Flutterwave webhook for every student payment (course fees, monthly
     * renewals, extra sessions). Authenticated by the secret hash set on the
     * Flutterwave dashboard (sent back as `verif-hash`). The body is only a
     * hint: the payment is re-read from Flutterwave before anything is granted,
     * exactly like the browser's verify.
     */
    public function flutterwaveWebhook(Request $request): JsonResponse
    {
        $expected = (string) config('services.flutterwave.webhook_hash', '');
        $given = (string) $request->header('verif-hash', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $reference = (string) ($request->input('data.tx_ref') ?? $request->input('txRef') ?? '');
        $payment = $reference !== ''
            ? Payment::query()->withoutGlobalScope(TenantScope::class)->where('reference', $reference)->first()
            : null;

        if ($payment && $payment->status !== 'success') {
            try {
                $data = CoursePayments::verify($payment);
                if (($data['status'] ?? '') === 'success') {
                    $this->completePayment($payment, $data);
                }
            } catch (\Throwable $e) {
                Log::error('Flutterwave webhook confirmation failed', ['reference' => $reference, 'err' => $e->getMessage()]);
            }
        }

        // Not a student payment: an academy paying Jorsas for its plan.
        if (! $payment && $reference !== '') {
            $tx = \App\Models\PlatformTransaction::query()
                ->where('reference', $reference)
                ->where('gateway', CoursePayments::FLUTTERWAVE)
                ->first();
            if ($tx) {
                try {
                    \App\Services\PlatformBilling::confirm($tx);
                } catch (\Throwable $e) {
                    Log::error('Flutterwave plan payment confirmation failed', ['reference' => $reference, 'err' => $e->getMessage()]);
                }
            }
        }

        // Always 200 for an authenticated call: an unknown reference is not
        // something Flutterwave can fix by retrying.
        return response()->json(['message' => 'Webhook received.']);
    }

    /**
     * Confirm a course payment reported by the platform's generic Paystack
     * webhook (PaystackWebhookController). Paystack delivers every event to ONE
     * webhook URL per account, so if that URL is the generic endpoint, this is
     * how a course payment still confirms when the student's browser never
     * comes back (and how a monthly renewal paid from a link confirms at all).
     */
    public function confirmFromWebhook(Payment $payment, array $gatewayData): void
    {
        if ($payment->status !== 'success') {
            $this->completePayment($payment, $gatewayData);
        }
    }

    /**
     * The admission-agent commission RATE (a 0..1 fraction) to apply for a sale of
     * the given course. Resolves the owning academy from the bound tenant when one
     * is present (the normal request path), else from the course row itself (e.g. a
     * webhook tick where no tenant is bound), so the correct per-academy rate is
     * used in every context; falls back to the platform default when the tenant
     * cannot be resolved. Kept separate from the platform's own settlement cut, and
     * distinct from the 5% referral discount the student already received on price.
     */
    private function agentCommissionRate(int $courseId): float
    {
        $tenant = (app()->bound('currentTenant') && app('currentTenant'))
            ? app('currentTenant')
            : null;

        if (! $tenant) {
            $tenantId = LmsCourse::withoutGlobalScope(TenantScope::class)
                ->whereKey($courseId)
                ->value('tenant_id');
            $tenant = $tenantId ? Tenant::find($tenantId) : null;
        }

        $percent = $tenant
            ? $tenant->agentCommissionPercent()
            : (float) config('saas.agent_commission_percent', 5);

        return $percent / 100;
    }

    private function handleZeroPayment(TrainingRegistration $registration): JsonResponse
    {
        $token = Str::random(80);

        $student = LmsStudent::query()->updateOrCreate(
            ['email' => $registration->email],
            [
                'first_name' => $registration->first_name,
                'last_name' => $registration->last_name,
                'phone' => $registration->phone_number,
                'selected_course_id' => $registration->course_id,
                'learning_mode' => $registration->learning_mode,
                'onboarding_completed' => false,
                'referred_by_agent_id' => $registration->referred_by_agent_id ?? $registration->registered_by_agent_id,
            ]
        );

        // Bridge to staff visibility: enroll into the course's active track now
        // (if one exists) so the assigned instructor sees the student without
        // waiting for password setup. Best-effort only: this convenience must
        // never abort the registration/approval + setup email below (a throw
        // here once surfaced to students as a false "Payment Issue").
        try {
            $this->enrollStudentIntoCourseTrack($student->id, $registration->course_id);
        } catch (\Throwable $e) {
            Log::warning('Enroll-into-track bridge failed (zero-payment); continuing', [
                'student_id' => $student->id,
                'course_id' => $registration->course_id,
                'err' => $e->getMessage(),
            ]);
        }

        $setupToken = Str::random(64);

        $registration->update([
            'status' => 'approved',
            'approved_at' => now(),
            'invite_token' => $setupToken,
        ]);

        LmsCourse::query()->where('id', $registration->course_id)->increment('registered_count');

        $agentRate = $this->agentCommissionRate($registration->course_id);
        if ($registration->referred_by_agent_id) {
            $fullPrice = (float) $registration->course_price / 0.95;
            AgentCommission::create([
                'agent_id' => $registration->referred_by_agent_id,
                'enrollment_id' => null,
                'course_price' => round($fullPrice, 2),
                'commission_amount' => round($fullPrice * $agentRate, 2),
                'status' => 'pending',
                'type' => 'referral',
                'notes' => "Student: {$registration->first_name} {$registration->last_name}, Course: {$registration->course_name}",
            ]);
        } elseif ($registration->registered_by_agent_id) {
            $fullPrice = (float) $registration->course_price;
            AgentCommission::create([
                'agent_id' => $registration->registered_by_agent_id,
                'enrollment_id' => null,
                'course_price' => round($fullPrice, 2),
                'commission_amount' => round($fullPrice * $agentRate, 2),
                'status' => 'pending',
                'type' => 'direct',
                'notes' => "Student: {$registration->first_name} {$registration->last_name}, Course: {$registration->course_name}",
            ]);
        }

        Payment::query()->create([
            'registration_id' => $registration->id,
            'reference' => 'FREE-' . Str::upper(Str::random(16)),
            'amount' => 0,
            'currency' => strtoupper((string) ($registration->charge_currency ?: 'NGN')),
            'status' => 'success',
            'gateway' => 'free',
        ]);

        $this->sendSetupEmail($registration, $setupToken);

        // The free sibling of the paid branch in completePayment(): same bell,
        // same reason. Notify::enrolment tolerates a null course (it says "a
        // course"), so a deleted course row degrades the wording rather than
        // breaking the registration that just succeeded.
        Notify::enrolment(
            LmsCourse::query()->find($registration->course_id),
            trim($registration->first_name . ' ' . $registration->last_name) ?: $registration->email,
            'Free enrolment.',
            null,
            'registration',
            $registration->id
        );

        return response()->json([
            'message' => 'Registration complete! Check your email for setup instructions.',
            'status' => 'success',
        ]);
    }

    private function completePayment(Payment $payment, ?array $gatewayData = null): JsonResponse
    {
        // The payment (found by its globally-unique reference) is the source of
        // truth for the tenant here, verify/webhook may run with no header, so
        // bind it before touching any TenantAware relation or create below.
        $this->bindTenantFromModel($payment);

        // A monthly course's later payments only extend access: no account to
        // provision, no setup email, no agent commission (agents earn on the
        // first month only). CourseBilling owns that path.
        if ($payment->kind === Payment::KIND_RENEWAL) {
            $status = CourseBilling::completeRenewal($payment, $gatewayData);

            return $status === 'review'
                ? response()->json([
                    'message' => 'Payment received but needs manual confirmation. Our team will verify it shortly.',
                    'status' => 'review',
                ])
                : response()->json(['message' => 'Payment confirmed. Thank you!', 'status' => 'success', 'renewal' => true]);
        }

        // Extra one-on-one sessions: adds to the student's allowance only.
        if ($payment->kind === Payment::KIND_EXTRA_SESSIONS) {
            $status = \App\Services\OneOnOneExtras::complete($payment, $gatewayData);

            return $status === 'review'
                ? response()->json([
                    'message' => 'Payment received but needs manual confirmation. Our team will verify it shortly.',
                    'status' => 'review',
                ])
                : response()->json(['message' => 'Payment confirmed. Thank you!', 'status' => 'success', 'extra_sessions' => true]);
        }

        $registration = $payment->registration;

        if (! $registration) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        // Reconciliation: before approving, confirm the gateway actually collected
        // the amount + currency we expected (Paystack returns amount in minor
        // units, kobo/cents). A mismatch (tampering, a partial charge, FX drift) is held
        // for manual review instead of silently granting access. Only runs when the
        // caller supplied the gateway payload (verify + webhook); a missing payload
        // preserves the previous behavior.
        if (is_array($gatewayData) && $gatewayData !== []) {
            $expectedMinor = (int) round(((float) $payment->amount) * 100);
            $gotMinor = (int) ($gatewayData['amount'] ?? 0);
            $expectedCurrency = strtoupper((string) ($payment->currency ?: 'NGN'));
            $gotCurrency = strtoupper((string) ($gatewayData['currency'] ?? ''));

            $amountMismatch = $gotMinor > 0 && $gotMinor !== $expectedMinor;
            $currencyMismatch = $gotCurrency !== '' && $gotCurrency !== $expectedCurrency;

            if ($amountMismatch || $currencyMismatch) {
                Log::warning('Payment reconciliation mismatch; holding for review', [
                    'reference' => $payment->reference,
                    'expected_amount_minor' => $expectedMinor,
                    'got_amount_minor' => $gotMinor,
                    'expected_currency' => $expectedCurrency,
                    'got_currency' => $gotCurrency,
                ]);

                $payment->update([
                    'status' => 'review',
                    'gateway_response' => $gatewayData,
                ]);

                return response()->json([
                    'message' => 'Payment received but needs manual confirmation. Our team will verify it shortly.',
                    'status' => 'review',
                ]);
            }
        }

        // Last line of defence against paying twice: this registration was
        // already paid by another payment, or the email got enrolled in a course
        // here in the meantime (two checkouts completed at once). The money is
        // taken, so hold it for a refund instead of enrolling twice or silently
        // swapping the student's course.
        $alreadyPaid = $registration->status === 'approved';
        $conflict = $alreadyPaid ? null : EnrollmentGuard::conflict(
            app()->bound('currentTenant') ? app('currentTenant') : null,
            (string) $registration->email,
            (int) $registration->course_id
        );
        if ($alreadyPaid || $conflict) {
            $payment->update([
                'status' => 'review',
                'gateway_response' => is_array($gatewayData) && $gatewayData !== [] ? $gatewayData : $payment->gateway_response,
            ]);

            $name = trim($registration->first_name . ' ' . $registration->last_name) ?: $registration->email;
            Notify::owner(
                'payment_duplicate',
                'Duplicate payment needs a refund: ' . $name,
                $name . ' (' . $registration->email . ') paid ' . CourseBilling::money($payment->currency, (float) $payment->amount)
                    . ' for ' . $registration->course_name . ' but is already enrolled at your academy. Please refund payment '
                    . $payment->reference . ' from your ' . ($payment->gateway === CoursePayments::FLUTTERWAVE ? 'Flutterwave' : 'Paystack') . ' dashboard.',
                'payment',
                $payment->id
            );
            Log::warning('Duplicate course payment held for refund', [
                'reference' => $payment->reference,
                'registration_id' => $registration->id,
            ]);

            return response()->json([
                'message' => 'You are already enrolled, so this extra payment will be refunded. The academy has been notified.',
                'status' => 'review',
                'duplicate' => true,
            ]);
        }

        $payment->update([
            'status' => 'success',
            'gateway_response' => is_array($gatewayData) && $gatewayData !== [] ? $gatewayData : $payment->gateway_response,
        ]);
        $registration->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        // Monthly course: this payment buys the first month (and, paid by card,
        // saves the card for automatic renewals). Best-effort like everything
        // after the money is captured: a failure leaves no paid_until, which
        // never locks the student out.
        try {
            CourseBilling::startFirstPeriod($registration, $payment, $gatewayData);
        } catch (\Throwable $e) {
            Log::warning('Monthly first period could not be started; continuing', [
                'registration_id' => $registration->id,
                'err' => $e->getMessage(),
            ]);
        }

        LmsStudent::query()->updateOrCreate(
            ['email' => $registration->email],
            [
                'training_registration_id' => $registration->id,
                'first_name' => $registration->first_name,
                'last_name' => $registration->last_name,
                'phone' => $registration->phone_number,
                'selected_course_id' => $registration->course_id,
                'learning_mode' => $registration->learning_mode,
                'onboarding_completed' => false,
                'referred_by_agent_id' => $registration->referred_by_agent_id ?? $registration->registered_by_agent_id,
            ]
        );

        // Bridge to staff visibility: enroll the paid student into the course's
        // active track now (if one exists), so the assigned instructor sees them
        // immediately rather than only after the student sets a password.
        // Best-effort only: a hiccup here must never abort payment confirmation
        // or the setup email below. This exact call (an undefined method at the
        // time) once threw AFTER the payment was marked paid, so the student was
        // charged but got no access email and saw a false "Payment Issue".
        try {
            $paidStudent = LmsStudent::query()->where('email', $registration->email)->first();
            if ($paidStudent) {
                $this->enrollStudentIntoCourseTrack($paidStudent->id, $registration->course_id);
            }
        } catch (\Throwable $e) {
            Log::warning('Enroll-into-track bridge failed (paid); continuing', [
                'email' => $registration->email,
                'course_id' => $registration->course_id,
                'err' => $e->getMessage(),
            ]);
        }

        LmsCourse::query()->where('id', $registration->course_id)->increment('registered_count');

        $agentRate = $this->agentCommissionRate($registration->course_id);
        if ($registration->referred_by_agent_id) {
            $fullPrice = (float) $registration->course_price / 0.95;
            AgentCommission::create([
                'agent_id' => $registration->referred_by_agent_id,
                'enrollment_id' => null,
                'course_price' => round($fullPrice, 2),
                'commission_amount' => round($fullPrice * $agentRate, 2),
                'status' => 'pending',
                'type' => 'referral',
                'notes' => "Student: {$registration->first_name} {$registration->last_name}, Course: {$registration->course_name}",
            ]);
        } elseif ($registration->registered_by_agent_id) {
            $fullPrice = (float) $registration->course_price;
            AgentCommission::create([
                'agent_id' => $registration->registered_by_agent_id,
                'enrollment_id' => null,
                'course_price' => round($fullPrice, 2),
                'commission_amount' => round($fullPrice * $agentRate, 2),
                'status' => 'pending',
                'type' => 'direct',
                'notes' => "Student: {$registration->first_name} {$registration->last_name}, Course: {$registration->course_name}",
            ]);
        }

        $setupToken = Str::random(64);
        $registration->update(['invite_token' => $setupToken]);

        $this->sendSetupEmail($registration, $setupToken);

        // The academy's own bell: a paid enrolment is the one event an owner
        // checks the portal for. The tenant is bound above (bindTenantFromModel),
        // because verify/webhook arrive with no tenant header.
        Notify::enrolment(
            LmsCourse::query()->find($registration->course_id),
            trim($registration->first_name . ' ' . $registration->last_name) ?: $registration->email,
            // Currency code + amount rather than a ₦ symbol: each academy is
            // paid in its own currency, so a hardcoded symbol would misreport it.
            // The owner is told the service-charge breakdown on EVERY payment;
            // for a first payment it rides on this enrolment bell (renewals
            // get their own, see CourseBilling::notifyOwnerOfRenewal).
            trim('Paid ' . CourseBilling::money($payment->currency, (float) $payment->amount)
                . ($registration->isMonthly() ? ' (first month of a monthly course)' : '') . '. '
                . CourseBilling::breakdownLine($payment, app()->bound('currentTenant') ? app('currentTenant') : null)),
            null,
            'registration',
            $registration->id
        );

        return response()->json([
            'message' => 'Payment confirmed! Check your email for academic portal setup instructions.',
            'status' => 'success',
        ]);
    }

    // ─── Admin intake management ─────────────────────────────────────

    public function pending(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $items = TrainingRegistration::query()
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();

        return response()->json($items);
    }

    public function adminIndex(Request $request)
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $items = TrainingRegistration::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $pendingRegistrations = TrainingRegistration::query()->where('status', 'pending')->count();

        return view('admin.lms.intake.index', [
            'items' => $items,
            'adminDir' => config('saas.admin_dir', 'admin'),
            'pendingRegistrations' => $pendingRegistrations,
            'studentCount' => LmsStudent::query()->count(),
            'tracks' => LmsTrack::query()->count(),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $registration = TrainingRegistration::query()->findOrFail($id);

        $setupToken = Str::random(64);

        $registration->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'invite_token' => $setupToken,
        ]);

        $this->sendSetupEmail($registration, $setupToken);

        $result = ['message' => 'Intake approved and setup email sent.'];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->back()->with('status', $result['message']);
    }

    public function decline(Request $request, int $id)
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $registration = TrainingRegistration::query()->findOrFail($id);

        $registration->update([
            'status' => 'rejected',
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'invite_token' => null,
        ]);

        $result = ['message' => 'Intake declined successfully.'];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->back()->with('status', $result['message']);
    }

    public function destroy(Request $request, int $id)
    {
        $this->ensureIntakeEnabled();
        $this->ensureSuperAdmin($request);

        $registration = TrainingRegistration::query()->findOrFail($id);
        $email = $registration->email;
        $registration->delete();

        $result = ['message' => "Intake record deleted for {$email}."];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result);
        }

        return redirect()->back()->with('status', $result['message']);
    }

    protected function ensureSuperAdmin(Request $request): void
    {
        $user = $request->user();
        $isSuperUser = $user && method_exists($user, 'isSuperUser') && $user->isSuperUser();

        abort_unless($isSuperUser, 403, 'Only super admin can perform this action.');
    }

    private function sendSetupEmail(TrainingRegistration $registration, string $setupToken): void
    {
        $baseUrl = config('saas.frontend_url');
        $setupLink = $baseUrl . '/lms/setup-password?token=' . urlencode($setupToken) . '&email=' . urlencode($registration->email);

        try {
            Mail::to($registration->email)->send(new PaymentConfirmationMail($registration, $setupLink));
        } catch (\Throwable $exception) {
            Log::error('Failed to send setup email', [
                'error' => $exception->getMessage(),
                'registration_id' => $registration->id,
            ]);
        }
    }
}
