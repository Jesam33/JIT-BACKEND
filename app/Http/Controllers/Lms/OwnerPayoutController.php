<?php

namespace App\Http\Controllers\Lms;

use App\Models\Payment;
use App\Models\Tenant;
use App\Services\CoursePayments;
use App\Services\FlutterwaveService;
use App\Support\PayoutCountries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The owner's "Course payments" page: the academy's country and currency, and
 * the bank its students' payments settle to.
 *
 * New links go through Flutterwave (any supported country, the academy's own
 * currency): the bank becomes a Flutterwave subaccount and every student
 * payment is split automatically. Academies linked on Paystack before the move
 * keep working (CoursePayments routes them) and see a prompt to re-link; the
 * old Paystack record is kept so cards saved there can still renew.
 *
 * Builds on OwnerAdminController's Paystack endpoints, which still handle the
 * agent-commission rate and the Paystack-only setup (no Flutterwave keys).
 *
 * Settings JSON: settings.country, settings.currency,
 * settings.flutterwave.{subaccount_id, id, country, bank_code, bank_name,
 * account_number, account_name, linked_at}.
 */
class OwnerPayoutController extends OwnerAdminController
{
    public function show(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }
        [$tenant] = $context;

        $base = parent::paymentSettings($request)->getData(true);
        $linkProvider = CoursePayments::linkProvider();
        $flw = (array) (data_get($tenant->settings, 'flutterwave') ?? []);
        $onFlutterwave = $tenant->flutterwaveSubaccountId() !== null;
        $onPaystack = ! empty(data_get($tenant->settings, 'paystack.subaccount_code'));
        [$locked, $lockReason] = $this->currencyLock($tenant);

        $payment = (array) ($base['payment'] ?? []);
        if ($onFlutterwave) {
            $account = (string) ($flw['account_number'] ?? '');
            $payment = array_merge($payment, [
                'configured' => true,
                'bank_name' => $flw['bank_name'] ?? null,
                'account_number_masked' => strlen($account) >= 4 ? str_repeat('•', max(0, strlen($account) - 4)) . substr($account, -4) : ($account ?: null),
                'account_name' => $flw['account_name'] ?? null,
                'business_name' => $flw['account_name'] ?? null,
            ]);
        }

        return response()->json(array_merge($base, [
            'payment' => $payment + [
                'provider' => $onFlutterwave ? CoursePayments::FLUTTERWAVE : ($onPaystack ? CoursePayments::PAYSTACK : null),
                // Linked on Paystack before the move (or that old link was
                // cleared): payments are paused until they link again here.
                'needs_relink' => ! $onFlutterwave && $linkProvider === CoursePayments::FLUTTERWAVE
                    && ($onPaystack || ! empty(data_get($tenant->settings, 'payout_relink_required'))),
            ],
            'academy' => [
                'country' => $tenant->payoutCountry(),
                'currency' => $tenant->currency(),
                'currency_locked' => $locked,
                'currency_lock_reason' => $lockReason,
            ],
            'link_provider' => $linkProvider,
            // Flutterwave: every supported country. Paystack-only: Nigeria.
            'countries' => $linkProvider === CoursePayments::FLUTTERWAVE
                ? PayoutCountries::options()
                : array_values(array_filter(PayoutCountries::options(), fn ($c) => $c['code'] === 'NG')),
            'gateway_ready' => CoursePayments::canLinkBanks(),
            // Flutterwave banks are fetched per country (banks endpoint below).
            'banks' => $linkProvider === CoursePayments::PAYSTACK ? ($base['banks'] ?? []) : [],
        ]));
    }

    /** Banks for one country (Flutterwave), cached for 12 hours. */
    public function banks(Request $request): JsonResponse
    {
        if (! $this->ownerContext($request)) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $country = strtoupper((string) $request->query('country', ''));
        if (! PayoutCountries::supports($country)) {
            return response()->json(['message' => 'That country isn’t supported for payouts yet.'], 422);
        }
        if (PayoutCountries::get($country)['form'] === 'iban') {
            return response()->json(['banks' => []]);
        }

        $service = app(FlutterwaveService::class);
        if (! $service->isConfigured()) {
            return response()->json(['banks' => [], 'message' => 'The payment gateway is not configured yet.']);
        }

        $banks = Cache::get('flw:banks:' . $country);
        if (! is_array($banks) || $banks === []) {
            $banks = $service->listBanks($country);
            if ($banks !== []) {
                Cache::put('flw:banks:' . $country, $banks, now()->addHours(12));
            }
        }

        return response()->json(['banks' => $banks]);
    }

    /** Branches of a bank (Ghana, Uganda, Tanzania, Rwanda). Often empty: then the owner types the code. */
    public function branches(Request $request, int $bankId): JsonResponse
    {
        if (! $this->ownerContext($request)) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        return response()->json(['branches' => app(FlutterwaveService::class)->bankBranches($bankId)]);
    }

    /**
     * Set the academy's country, which sets the currency it sells in. Course
     * prices are numbers in that currency, so changing it after students have
     * paid would misprice everything: blocked then.
     */
    public function setCountry(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }
        [$tenant] = $context;

        $validated = $request->validate(['country' => ['required', 'string', 'size:2']]);
        $country = strtoupper($validated['country']);

        if (! PayoutCountries::supports($country)) {
            return response()->json(['message' => 'That country isn’t supported for payouts yet.'], 422);
        }
        if (CoursePayments::linkProvider() === CoursePayments::PAYSTACK && $country !== 'NG') {
            return response()->json(['message' => 'Payouts outside Nigeria aren’t available yet.'], 422);
        }

        $currency = PayoutCountries::currencyFor($country);
        if ($currency !== $tenant->currency()) {
            [$locked, $reason] = $this->currencyLock($tenant);
            if ($locked) {
                return response()->json(['message' => $reason], 422);
            }
            // A bank linked for another country/currency can't receive this one.
            if ($tenant->payoutConfigured()) {
                return response()->json(['message' => 'Disconnect your current bank before changing your academy’s country.'], 422);
            }
        }

        $settings = (array) ($tenant->settings ?? []);
        $changed = ($settings['currency'] ?? 'NGN') !== $currency;
        $settings['country'] = $country;
        $settings['currency'] = $currency;
        $tenant->update(['settings' => $settings]);

        return response()->json([
            'message' => $changed
                ? "Your academy now sells in {$currency}. Check your course prices: they are now read as {$currency}."
                : 'Saved.',
            'academy' => ['country' => $country, 'currency' => $currency, 'currency_locked' => false, 'currency_lock_reason' => null],
        ]);
    }

    /**
     * Link the academy's bank. Flutterwave when it's the link provider; the
     * agent-rate-only update, a pasted Paystack code, and the Paystack-only
     * setup fall through to the original endpoint.
     */
    public function update(Request $request): JsonResponse
    {
        $context = $this->ownerContext($request);
        if (! $context) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }
        [$tenant] = $context;

        if ($request->boolean('disconnect')) {
            return $this->disconnect($request, $tenant);
        }

        $rateOnly = $request->has('agent_commission_percent')
            && ! $request->hasAny(['subaccount_code', 'bank_code', 'account_number', 'disconnect']);
        if ($rateOnly || $request->filled('subaccount_code') || CoursePayments::linkProvider() !== CoursePayments::FLUTTERWAVE) {
            return parent::updatePaymentSettings($request);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'bank_code' => ['nullable', 'string', 'max:40'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:40'],
            // The legal name on the account (helps the bank match it).
            'account_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:30'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'routing_number' => ['nullable', 'string', 'max:20'],
            'branch_code' => ['nullable', 'string', 'max:40'],
        ], [
            'account_name.required' => 'Enter the name on the bank account.',
            'phone.required' => 'Enter a phone number for the account.',
        ]);

        $country = strtoupper($validated['country']);
        $spec = PayoutCountries::get($country);
        if (! $spec) {
            return response()->json(['message' => 'That country isn’t supported for payouts yet.'], 422);
        }

        // Country-specific fields.
        $missing = match ($spec['form']) {
            'iban' => empty($validated['swift_code']) ? 'Enter your bank’s SWIFT/BIC code.' : null,
            'routing' => empty($validated['bank_code']) ? 'Choose your bank.' : (empty($validated['routing_number']) ? 'Enter your routing number.' : (empty($validated['swift_code']) ? 'Enter your bank’s SWIFT code.' : null)),
            default => empty($validated['bank_code']) ? 'Choose your bank.' : null,
        };
        if ($missing) {
            return response()->json(['message' => $missing, 'errors' => ['bank' => [$missing]]], 422);
        }

        // The bank's country fixes the currency the academy sells in.
        $currency = $spec['currency'];
        if ($currency !== $tenant->currency()) {
            [$locked, $reason] = $this->currencyLock($tenant);
            if ($locked) {
                return response()->json(['message' => $reason], 422);
            }
        }

        $accountNumber = preg_replace('/\s+/', '', (string) $validated['account_number']);
        $meta = [];
        if (! empty($validated['swift_code'])) {
            $meta[] = ['meta_name' => 'swift_code', 'meta_value' => strtoupper(trim($validated['swift_code']))];
        }
        if (! empty($validated['routing_number'])) {
            $meta[] = ['meta_name' => 'routing_number', 'meta_value' => trim($validated['routing_number'])];
        }
        if (! empty($validated['branch_code'])) {
            $meta[] = ['meta_name' => 'branch_code', 'meta_value' => trim($validated['branch_code'])];
        }

        $owner = $context[1] ?? null;
        $fields = [
            // IBAN countries have no bank list; Flutterwave takes the country code.
            'account_bank' => $spec['form'] === 'iban' ? $country : trim((string) $validated['bank_code']),
            'account_number' => $accountNumber,
            'business_name' => trim($validated['account_name']),
            'business_email' => (string) ($owner?->email ?? data_get($tenant->settings, 'profile.contact.email', '')),
            'business_mobile' => trim($validated['phone']),
            'business_contact' => trim($validated['account_name']),
            'business_contact_mobile' => trim($validated['phone']),
            'country' => $country,
        ];
        if ($meta) {
            $fields['meta'] = $meta;
        }

        $service = app(FlutterwaveService::class);
        $result = $service->createSubaccount($fields, $tenant->commissionPercent());

        if (! FlutterwaveService::ok($result) || empty($result['data']['subaccount_id'])) {
            return response()->json([
                'message' => $result['message'] ?? 'Could not link that account. Check the bank details and try again.',
            ], 422);
        }

        $settings = (array) ($tenant->settings ?? []);

        // Replacing an earlier Flutterwave link: remove the old subaccount.
        $old = (array) ($settings['flutterwave'] ?? []);
        if (! empty($old['id'])) {
            $service->deleteSubaccount((int) $old['id']);
        }

        $settings['flutterwave'] = [
            'subaccount_id' => (string) $result['data']['subaccount_id'],
            'id' => (int) ($result['data']['id'] ?? 0),
            'country' => $country,
            'bank_code' => $fields['account_bank'],
            'bank_name' => $validated['bank_name'] ?? ($result['data']['bank_name'] ?? null),
            'account_number' => $accountNumber,
            'account_name' => $result['data']['full_name'] ?? ($result['data']['account_name'] ?? trim($validated['account_name'])),
            'linked_at' => now()->toIso8601String(),
        ];
        $settings['country'] = $country;
        $settings['currency'] = $currency;
        // Re-linked after the move to Flutterwave: the "link your bank again" ask is done.
        unset($settings['payout_relink_required']);
        $tenant->update(['settings' => $settings]);

        return response()->json([
            'message' => 'Bank linked. Students now pay in ' . $currency . ' and your share goes straight to this account.',
            'configured' => true,
            'provider' => CoursePayments::FLUTTERWAVE,
            'account_name' => $settings['flutterwave']['account_name'],
        ]);
    }

    /** Account holder name (Nigeria): Flutterwave when it's the provider, else Paystack. */
    public function resolve(Request $request): JsonResponse
    {
        if (CoursePayments::linkProvider() !== CoursePayments::FLUTTERWAVE) {
            return parent::resolveBankAccount($request);
        }
        if (! $this->ownerContext($request)) {
            return response()->json(['message' => 'Not authorized.'], 403);
        }

        $validated = $request->validate([
            'account_number' => ['required', 'string', 'regex:/^\d{10}$/'],
            'bank_code' => ['required', 'string', 'max:40'],
        ]);

        $name = app(FlutterwaveService::class)->resolveAccount($validated['account_number'], $validated['bank_code']);

        return response()->json([
            'account_name' => $name,
            'message' => $name ? null : 'Could not confirm this account. Check the number and the bank.',
        ]);
    }

    private function disconnect(Request $request, Tenant $tenant): JsonResponse
    {
        $settings = (array) ($tenant->settings ?? []);
        $flw = (array) ($settings['flutterwave'] ?? []);

        if (empty($flw['subaccount_id'])) {
            // Nothing on Flutterwave: the original Paystack disconnect.
            return parent::updatePaymentSettings($request);
        }

        if (! empty($flw['id'])) {
            app(FlutterwaveService::class)->deleteSubaccount((int) $flw['id']);
        }
        unset($settings['flutterwave']);
        $tenant->update(['settings' => $settings]);

        return response()->json([
            'message' => 'Bank disconnected. Students can’t pay for courses until you link a bank again.',
            'configured' => $tenant->fresh()->payoutConfigured(),
        ]);
    }

    /**
     * Whether the academy's currency is fixed: once a student has paid, prices
     * and records are in that currency.
     *
     * @return array{0: bool, 1: ?string}
     */
    private function currencyLock(Tenant $tenant): array
    {
        $paid = Payment::query()
            ->where('status', 'success')
            ->where('amount', '>', 0)
            ->exists();

        return $paid
            ? [true, 'Students have already paid in ' . $tenant->currency() . ', so your academy’s currency can’t change. Contact support if you need to move countries.']
            : [false, null];
    }
}
