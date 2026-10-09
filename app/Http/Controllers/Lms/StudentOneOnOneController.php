<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsModule;
use App\Models\LmsOneOnOneCredit;
use App\Models\LmsScheduledClass;
use App\Models\LmsStudent;
use App\Models\Payment;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\CoursePayments;
use App\Services\OneOnOneBooking;
use App\Services\OneOnOneExtras;
use App\Services\OneOnOneOutcomes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A one-on-one student books their own private sessions: see the open slots
 * in their teacher's week, book one (or the same time every week), cancel one
 * inside the cancellation window, report a session their teacher missed, and
 * buy extra sessions once their allowance is used. Rules live in
 * App\Services\OneOnOneBooking / OneOnOneOutcomes / OneOnOneExtras.
 */
class StudentOneOnOneController extends BaseLmsController
{
    /** Everything the "Book a session" page needs in one call. */
    public function show(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        if (! $student->isOneOnOne()) {
            return response()->json(['message' => 'Booking is only for one-on-one students.'], 403);
        }

        $settings = OneOnOneBooking::settings();
        $course = $student->course;

        $upcoming = LmsScheduledClass::query()
            ->with('module:id,title')
            ->where('student_id', $student->id)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (LmsScheduledClass $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'module_title' => $c->module?->title,
                'starts_at' => $c->starts_at?->toIso8601String(),
                'ends_at' => $c->ends_at?->toIso8601String(),
                'booked_by' => $c->booked_by ?? 'teacher',
                'weekly' => $c->series_id !== null,
                'can_cancel' => OneOnOneBooking::studentCancelBlockedReason($c) === null,
            ])->values();

        // Recent past sessions, so the student can see what was recorded and
        // report one their teacher missed.
        $recent = LmsScheduledClass::query()
            ->with('module:id,title')
            ->where('student_id', $student->id)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', now())
            ->where('starts_at', '>=', now()->subDays(30))
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (LmsScheduledClass $c) => [
                'id' => $c->id,
                'module_title' => $c->module?->title ?? $c->title,
                'starts_at' => $c->starts_at?->toIso8601String(),
                'outcome' => $c->outcome,
                'reported' => $c->no_show_reported_at !== null,
                'can_report' => OneOnOneOutcomes::reportBlockedReason($c) === null,
            ])->values();

        $extraPrice = OneOnOneExtras::price($student);

        return response()->json([
            'teacher_name' => $student->oneOnOneTeacher?->name,
            'blocked_reason' => OneOnOneBooking::blockedReason($student),
            'slots' => collect(OneOnOneBooking::openSlots($student))->map(fn (array $s) => [
                'starts_at' => $s['starts_at']->toIso8601String(),
                'ends_at' => $s['ends_at']->toIso8601String(),
            ])->values(),
            'session_minutes' => OneOnOneBooking::sessionMinutes($course),
            'allowance' => OneOnOneBooking::allowance($student),
            'extras' => $extraPrice !== null ? [
                'price' => $extraPrice,
                'currency' => Tenant::find($student->tenant_id)?->currency() ?? 'NGN',
                'max' => OneOnOneExtras::MAX_PER_PURCHASE,
            ] : null,
            'modules' => LmsModule::query()
                ->where('course_id', $student->selected_course_id)
                ->orderBy('sort_order')
                ->get(['id', 'title'])
                ->map(fn ($m) => ['id' => $m->id, 'title' => $m->title])->values(),
            'upcoming' => $upcoming,
            'recent' => $recent,
            'rules' => [
                'min_notice_hours' => $settings['min_notice_hours'],
                'cancel_window_hours' => $settings['cancel_window_hours'],
                'horizon_days' => $settings['horizon_days'],
                'repeat_max_weeks' => $settings['repeat_max_weeks'],
            ],
        ]);
    }

    /** Book a slot, optionally the same time every week (repeat_weeks). */
    public function book(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $max = OneOnOneBooking::settings()['repeat_max_weeks'];

        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'module_id' => ['required', 'integer'],
            'repeat_weeks' => ['nullable', 'integer', 'min:1', 'max:' . $max],
        ], [
            'module_id.required' => 'Choose what you want to cover in this session.',
            'repeat_weeks.max' => "You can book up to {$max} weeks at a time.",
        ]);

        $result = OneOnOneBooking::bookSeries(
            $student,
            Carbon::parse($validated['starts_at']),
            (int) $validated['module_id'],
            (int) ($validated['repeat_weeks'] ?? 1)
        );

        $first = $result['booked'][0];

        return response()->json([
            'session' => [
                'id' => $first->id,
                'title' => $first->title,
                'starts_at' => $first->starts_at?->toIso8601String(),
                'ends_at' => $first->ends_at?->toIso8601String(),
            ],
            'booked' => collect($result['booked'])->map(fn (LmsScheduledClass $s) => [
                'id' => $s->id,
                'starts_at' => $s->starts_at?->toIso8601String(),
            ])->values(),
            'skipped' => collect($result['skipped'])->map(fn (array $s) => [
                'starts_at' => $s['starts_at']->toIso8601String(),
                'reason' => $s['reason'],
            ])->values(),
        ], 201);
    }

    public function cancel(Request $request, int $sessionId): JsonResponse
    {
        $student = $this->studentOrFail($request);

        OneOnOneBooking::cancelByStudent($student, $this->ownSession($student, $sessionId));

        return response()->json(['ok' => true]);
    }

    /** "My teacher didn't come": the owner reviews it. */
    public function reportNoShow(Request $request, int $sessionId): JsonResponse
    {
        $student = $this->studentOrFail($request);

        OneOnOneOutcomes::report($student, $this->ownSession($student, $sessionId));

        return response()->json(['ok' => true]);
    }

    /** Start a Paystack checkout for extra sessions. */
    public function buyExtras(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $validated = $request->validate([
            'sessions' => ['required', 'integer', 'min:1', 'max:' . OneOnOneExtras::MAX_PER_PURCHASE],
        ]);

        $tenant = Tenant::find($student->tenant_id);

        // Same money-safety rule as every student payment: a non-primary
        // academy with no linked bank must not collect into the platform account.
        if (! CoursePayments::gatewayFor($tenant)) {
            return $tenant && ! $tenant->isPrimary()
                ? response()->json(['message' => 'Your academy is finishing its payment setup. Please try again later or contact them.'], 409)
                : response()->json(['message' => 'Payments are not available right now. Please try again later.'], 503);
        }

        [$payment, $fee] = OneOnOneExtras::start($student, (int) $validated['sessions']);

        try {
            $checkout = CoursePayments::start(
                $payment,
                $tenant,
                (string) $student->email,
                trim($student->first_name . ' ' . $student->last_name),
                OneOnOneExtras::callbackUrl($student, $payment->reference),
                [
                    'registration_id' => $payment->registration_id,
                    'purpose' => 'one_on_one_extra_sessions',
                    'sessions' => (int) $validated['sessions'],
                ],
                $fee,
                'Extra one-on-one sessions'
            );
        } catch (\Throwable $e) {
            Log::error('Extra sessions initialization failed', ['student_id' => $student->id, 'err' => $e->getMessage()]);
            $payment->update(['status' => 'failed']);

            return response()->json(['message' => 'Could not start the payment. Please try again.'], 500);
        }

        if (! $checkout['url']) {
            $payment->update(['status' => 'failed']);

            return response()->json(['message' => $checkout['message'] ?: 'Could not start the payment. Please try again.'], 502);
        }

        return response()->json(['authorization_url' => $checkout['url'], 'reference' => $payment->reference]);
    }

    /** Confirm an extra-sessions payment after Paystack sends the student back. */
    public function verifyExtras(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);
        $reference = (string) $request->query('reference', '');

        // Only this student's own purchase can be confirmed here.
        $paymentId = $reference !== ''
            ? LmsOneOnOneCredit::query()->withoutGlobalScope(TenantScope::class)
                ->where('student_id', $student->id)
                ->whereIn('payment_id', Payment::query()->withoutGlobalScope(TenantScope::class)
                    ->where('reference', $reference)
                    ->where('kind', Payment::KIND_EXTRA_SESSIONS)
                    ->select('id'))
                ->value('payment_id')
            : null;

        $payment = $paymentId ? Payment::query()->withoutGlobalScope(TenantScope::class)->find($paymentId) : null;
        if (! $payment) {
            return response()->json(['message' => 'Payment reference not found.'], 404);
        }

        if ($payment->status !== 'success') {
            try {
                $data = CoursePayments::verify($payment);
            } catch (\Throwable $e) {
                Log::error('Extra sessions verification failed', ['reference' => $reference, 'err' => $e->getMessage()]);

                return response()->json(['message' => 'Could not verify the payment yet. Please refresh in a moment.'], 500);
            }

            if (($data['status'] ?? '') !== 'success') {
                return response()->json(['message' => 'Payment not confirmed yet.', 'status' => $data['status'] ?? 'unknown']);
            }

            if (OneOnOneExtras::complete($payment, (array) $data) === 'review') {
                return response()->json([
                    'message' => 'Payment received but needs manual confirmation. Your academy will confirm it shortly.',
                    'status' => 'review',
                ]);
            }
        }

        return response()->json(['message' => 'Payment confirmed. Your extra sessions are ready to book.', 'status' => 'success']);
    }

    private function ownSession(LmsStudent $student, int $sessionId): LmsScheduledClass
    {
        return LmsScheduledClass::query()
            ->where('student_id', $student->id)
            ->findOrFail($sessionId);
    }

    private function studentOrFail(Request $request): LmsStudent
    {
        $this->ensureLmsEnabled();
        $session = $this->sessionFromRequest($request, 'student');
        $student = $session ? LmsStudent::query()->find($session->user_id) : null;
        if (! $student) {
            abort(401, 'Unauthorized');
        }

        return $student;
    }
}
