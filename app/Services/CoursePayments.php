<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Which payment gateway an academy's student payments go through, and the one
 * place that talks to it: start a checkout, confirm a payment, charge a saved
 * card. Course fees, monthly renewals, extra one-on-one sessions and owner
 * invites all come through here.
 *
 *   Flutterwave  every academy that links its bank now (any supported country,
 *                its own currency). The default for new links.
 *   Paystack     academies that linked a Nigerian bank before the move; they
 *                keep working until the owner re-links on the payout page.
 *   Primary      the platform's own academy has no subaccount and settles to
 *                the platform account on the default gateway.
 *
 * A non-primary academy with no linked bank gets no gateway at all, so its
 * fees can never land in the platform account (callers answer 409).
 *
 * The gateway is stamped on each Payment (payments.gateway) when it starts, so
 * a payment is always confirmed with the gateway that took it, even if the
 * academy has re-linked since.
 */
class CoursePayments
{
    public const FLUTTERWAVE = 'flutterwave';

    public const PAYSTACK = 'paystack';

    public static function flutterwave(): FlutterwaveService
    {
        return app(FlutterwaveService::class);
    }

    public static function paystack(): PaystackService
    {
        return app(PaystackService::class);
    }

    /** The gateway new bank links go through: Flutterwave, else Paystack (Nigeria only). */
    public static function linkProvider(): ?string
    {
        if (static::flutterwave()->isConfigured()) {
            return self::FLUTTERWAVE;
        }

        return static::paystack()->isConfigured() ? self::PAYSTACK : null;
    }

    public static function canLinkBanks(): bool
    {
        return static::linkProvider() !== null;
    }

    /** The gateway this academy's student payments use, or null (can't take money yet). */
    public static function gatewayFor(?Tenant $tenant): ?string
    {
        if ($tenant && $tenant->flutterwaveSubaccountId() && static::flutterwave()->isConfigured()) {
            return self::FLUTTERWAVE;
        }

        if ($tenant && CourseBilling::subaccountCode($tenant) && static::paystack()->isConfigured()) {
            return self::PAYSTACK;
        }

        // The platform's own academy settles to the platform account.
        if (! $tenant || $tenant->isPrimary()) {
            return static::linkProvider();
        }

        return null;
    }

    /** The academy's subaccount on that gateway (null for the primary academy). */
    public static function subaccountFor(?Tenant $tenant, ?string $gateway): ?string
    {
        if (! $tenant) {
            return null;
        }

        return match ($gateway) {
            self::FLUTTERWAVE => $tenant->flutterwaveSubaccountId(),
            self::PAYSTACK => CourseBilling::subaccountCode($tenant),
            default => null,
        };
    }

    /** Whether this academy's payments are split to its own bank. */
    public static function linked(?Tenant $tenant): bool
    {
        return static::subaccountFor($tenant, static::gatewayFor($tenant)) !== null;
    }

    /**
     * Start a checkout for a pending Payment. Stamps the gateway on it.
     *
     * @return array{url: ?string, message: ?string}
     */
    public static function start(Payment $payment, ?Tenant $tenant, string $email, string $name, string $callbackUrl, array $meta, ?float $serviceCharge, string $title = 'Course payment'): array
    {
        $gateway = static::gatewayFor($tenant);
        if (! $gateway) {
            return ['url' => null, 'message' => 'This academy is finishing its payment setup. Please try again later.'];
        }

        $payment->forceFill(['gateway' => $gateway])->save();
        $subaccount = static::subaccountFor($tenant, $gateway);

        if ($gateway === self::FLUTTERWAVE) {
            $body = static::flutterwave()->initializePayment(
                $payment->reference,
                (float) $payment->amount,
                (string) $payment->currency,
                $email,
                $name,
                $callbackUrl,
                $subaccount,
                $subaccount ? $serviceCharge : null,
                $meta,
                $title
            );

            return [
                'url' => FlutterwaveService::ok($body) ? ($body['data']['link'] ?? null) : null,
                'message' => FlutterwaveService::ok($body) ? null : ($body['message'] ?? null),
            ];
        }

        $body = static::paystack()->initializeTransaction(
            $email,
            (float) $payment->amount,
            $payment->reference,
            $meta,
            $callbackUrl,
            $subaccount,
            (string) $payment->currency,
            $subaccount ? $serviceCharge : null
        );

        return [
            'url' => $body['data']['authorization_url'] ?? null,
            'message' => isset($body['data']['authorization_url']) ? null : ($body['message'] ?? null),
        ];
    }

    /**
     * The payment as the gateway that took it reports it, in Paystack's shape
     * ('status' => 'success', amount in minor units, currency, authorization).
     * Null when the gateway couldn't be reached.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(Payment $payment): ?array
    {
        if (($payment->gateway ?: self::PAYSTACK) === self::FLUTTERWAVE) {
            $body = static::flutterwave()->verifyByReference((string) $payment->reference);
            if (! FlutterwaveService::ok($body) || empty($body['data'])) {
                // "No transaction was found" = the student hasn't paid yet.
                return ['status' => 'pending', 'gateway' => self::FLUTTERWAVE];
            }

            $data = FlutterwaveService::normalize((array) $body['data']);

            // Belt and braces: the transaction must be the one we asked for.
            return ($data['reference'] ?? null) === $payment->reference
                ? $data
                : ['status' => 'mismatch', 'gateway' => self::FLUTTERWAVE];
        }

        $body = static::paystack()->verifyTransaction((string) $payment->reference);

        return (array) ($body['data'] ?? []) + ['gateway' => self::PAYSTACK];
    }

    /**
     * Charge a saved card for a pending renewal Payment. Returns the gateway
     * result in Paystack's shape ('status' => 'success' when the money was
     * taken), or a 'failed' shape with a message.
     *
     * @param  array{code: string, gateway?: string}  $card
     * @return array<string, mixed>
     */
    public static function chargeSaved(Payment $payment, ?Tenant $tenant, array $card, string $email, ?float $serviceCharge): array
    {
        $gateway = ($card['gateway'] ?? self::PAYSTACK) === self::FLUTTERWAVE ? self::FLUTTERWAVE : self::PAYSTACK;
        $subaccount = static::subaccountFor($tenant, $gateway);

        // A non-primary academy must have a subaccount on the card's gateway,
        // or the money would land in the platform account.
        if ($tenant && ! $tenant->isPrimary() && ! $subaccount) {
            return ['status' => 'failed', 'message' => 'The academy is not linked on this card\'s payment gateway.'];
        }

        $payment->forceFill(['gateway' => $gateway])->save();

        try {
            if ($gateway === self::FLUTTERWAVE) {
                $body = static::flutterwave()->chargeToken(
                    (string) $card['code'],
                    $email,
                    (float) $payment->amount,
                    (string) $payment->currency,
                    (string) $payment->reference,
                    $subaccount,
                    $subaccount ? $serviceCharge : null,
                    $tenant?->payoutCountry()
                );

                if (! FlutterwaveService::ok($body) || empty($body['data'])) {
                    return ['status' => 'failed', 'message' => $body['message'] ?? null, 'gateway' => self::FLUTTERWAVE];
                }

                return FlutterwaveService::normalize((array) $body['data']);
            }

            $body = static::paystack()->chargeAuthorization(
                $email,
                (float) $payment->amount,
                (string) $card['code'],
                (string) $payment->reference,
                ['purpose' => 'monthly_renewal'],
                $subaccount,
                (string) $payment->currency,
                $subaccount ? $serviceCharge : null
            );

            return (array) ($body['data'] ?? []) + ['message' => $body['message'] ?? null, 'gateway' => self::PAYSTACK];
        } catch (\Throwable $e) {
            Log::warning('Saved-card charge failed', ['reference' => $payment->reference, 'err' => $e->getMessage()]);

            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }
}
