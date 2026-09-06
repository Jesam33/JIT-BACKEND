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
use App\Services\CurrencyService;
use App\Services\PaystackService;
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
     * The currently-bound institute's Paystack subaccount code, or null. When
     * present, course fees are split to the institute's own bank; when absent
     * (primary institute, or one that hasn't connected a payout account yet),
     * the transaction settles to the platform account exactly as before.
     */
    private function tenantSubaccountCode(): ?string
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant) {
            return null;
        }

        $code = data_get($tenant->settings, 'paystack.subaccount_code');

        return is_string($code) && $code !== '' ? $code : null;
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
            ->orderBy('title')
            ->get()
            ->map(fn (LmsCourse $course) => [
                'id' => $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
                'description' => $course->description,
                'price' => (float) $course->price,
                'max_students' => $course->max_students,
                'registered_count' => $course->registered_count,
                'slots_remaining' => $course->slotsRemaining(),
                'is_full' => $course->isFull(),
                'is_live_available' => $course->is_live_available,
                'is_prerecorded_available' => $course->is_prerecorded_available,
            ])
            ->values();

        return response()->json($courses);
    }

    public function courseDetail(string $slug): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $course = LmsCourse::query()
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
            'max_students' => $course->max_students,
            'registered_count' => $course->registered_count,
            'slots_remaining' => $course->slotsRemaining(),
            'is_full' => $course->isFull(),
            'is_live_available' => $course->is_live_available,
            'is_prerecorded_available' => $course->is_prerecorded_available,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $this->ensureIntakeEnabled();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'date_of_birth' => ['required', 'date'],
            'qualification_level' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'],
            'whatsapp' => ['required', 'string', 'max:40'],
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'learning_mode' => ['required', 'in:live,pre_recorded'],
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

        $course = LmsCourse::query()->findOrFail($validated['course_id']);

        if ($course->isFull()) {
            return response()->json([
                'message' => 'This course is full. Please join the waitlist.',
                'is_full' => true,
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
        $price = ($validated['learning_mode'] === 'pre_recorded' && $course->prerecorded_price !== null)
            ? (float) $course->prerecorded_price
            : (float) $course->price;
        if (! empty($validated['referral_code'])) {
            $agent = \App\Models\Agent::where('referral_code', $validated['referral_code'])
                ->where('status', 'approved')
                ->first();
            if ($agent) {
                $referredByAgentId = $agent->id;
                $price = round($price * 0.95, 2);
            }
        }

        // Freeze the CHARGE currency + amount. The referral discount is applied to
        // the base NGN above; the charge currency is resolved from the visitor's
        // country: NGN for Nigeria (and, while USD charging is disabled, for
        // everyone). Only when USD charging is enabled AND the buyer is outside
        // Nigeria is the NGN amount converted to USD and frozen in USD. If FX is
        // unavailable at that moment we fall back to charging NGN rather than guess.
        $currency = app(CurrencyService::class);
        $chargeCurrency = $currency->chargeCurrencyForCountry($validated['country'] ?? null);
        $chargeAmount = $price;
        if ($chargeCurrency !== 'NGN') {
            $converted = $currency->convert($price, $chargeCurrency);
            if ($converted !== null && $converted > 0) {
                $chargeAmount = round($converted, 2);
            } else {
                $chargeCurrency = 'NGN';
            }
        }

        $registration = TrainingRegistration::query()->create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'date_of_birth' => $validated['date_of_birth'],
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
        ]);

        return response()->json([
            'message' => 'Registration created. Proceed to payment.',
            'registration_id' => $registration->id,
            'course' => [
                'title' => $course->title,
                'price' => $chargeAmount,
                'charge_currency' => $chargeCurrency,
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

        // Unlinked-institute guard (money safety). A non-primary institute that
        // hasn't linked its payout subaccount yet must not collect course fees
        // into the PLATFORM account. Block paid checkout until they link a bank;
        // the storefront reflects this upfront via `purchasable`, so this is the
        // defense-in-depth path (e.g. a stale page). Free courses (≤ 0) never reach
        // here, and the primary institute is exempt, it legitimately settles to
        // the platform account. Mirrors the primary-slug idiom used below at the
        // callback branch.
        if ((float) $registration->course_price > 0) {
            $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
            $isNonPrimary = $tenant && $tenant->slug && $tenant->slug !== config('saas.primary_slug', 'jorsas');
            if ($isNonPrimary && ! $this->tenantSubaccountCode()) {
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
            $paystack = app(PaystackService::class);

            if (! $paystack->isConfigured()) {
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

            // The currency this registration was frozen to charge in (NGN unless
            // USD charging is enabled and the buyer registered from outside Nigeria).
            $chargeCurrency = strtoupper((string) ($registration->charge_currency ?: 'NGN'));

            $response = $paystack->initializeTransaction(
                $registration->email,
                (float) $registration->course_price,
                $reference,
                [
                    'registration_id' => $registration->id,
                    'course_name' => $registration->course_name,
                ],
                $callbackUrl,
                // Settle course fees to the institute's own Paystack subaccount
                // (its bank) when it has configured one; primary/unconfigured
                // institutes fall back to the platform account, as before.
                $this->tenantSubaccountCode(),
                $chargeCurrency
            );

            Payment::query()->create([
                'registration_id' => $registration->id,
                'reference' => $reference,
                'amount' => $registration->course_price,
                'currency' => $chargeCurrency,
                'status' => 'pending',
                'gateway' => 'paystack',
            ]);

            return response()->json([
                'authorization_url' => $response['data']['authorization_url'] ?? null,
                'reference' => $reference,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Paystack initialization failed', [
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
            $paystack = app(PaystackService::class);
            $response = $paystack->verifyTransaction($validated['reference']);

            if (($response['data']['status'] ?? '') === 'success') {
                return $this->completePayment($payment, $response['data'] ?? null);
            }

            return response()->json([
                'message' => 'Payment not yet confirmed.',
                'status' => $response['data']['status'] ?? 'unknown',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Paystack verification failed', [
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

        if ($registration->referred_by_agent_id) {
            $fullPrice = (float) $registration->course_price / 0.95;
            AgentCommission::create([
                'agent_id' => $registration->referred_by_agent_id,
                'enrollment_id' => null,
                'course_price' => round($fullPrice, 2),
                'commission_amount' => round($fullPrice * 0.10, 2),
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
                'commission_amount' => round($fullPrice * 0.10, 2),
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

        $registration = $payment->registration;

        if (! $registration) {
            return response()->json(['message' => 'Registration not found.'], 404);
        }

        // Reconciliation: before approving, confirm the gateway actually collected
        // the amount + currency we expected (Paystack returns amount in minor units
        //, kobo/cents). A mismatch (tampering, a partial charge, FX drift) is held
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

        $payment->update([
            'status' => 'success',
            'gateway_response' => is_array($gatewayData) && $gatewayData !== [] ? $gatewayData : $payment->gateway_response,
        ]);
        $registration->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

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

        if ($registration->referred_by_agent_id) {
            $fullPrice = (float) $registration->course_price / 0.95;
            AgentCommission::create([
                'agent_id' => $registration->referred_by_agent_id,
                'enrollment_id' => null,
                'course_price' => round($fullPrice, 2),
                'commission_amount' => round($fullPrice * 0.10, 2),
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
                'commission_amount' => round($fullPrice * 0.10, 2),
                'status' => 'pending',
                'type' => 'direct',
                'notes' => "Student: {$registration->first_name} {$registration->last_name}, Course: {$registration->course_name}",
            ]);
        }

        $setupToken = Str::random(64);
        $registration->update(['invite_token' => $setupToken]);

        $this->sendSetupEmail($registration, $setupToken);

        return response()->json([
            'message' => 'Payment confirmed! Check your email for LMS setup instructions.',
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
            'adminDir' => env('ADMIN_DIR', 'admin'),
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
