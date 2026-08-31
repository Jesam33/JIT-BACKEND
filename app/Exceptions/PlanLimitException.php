<?php

namespace App\Exceptions;

use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a tenant action would exceed its plan's limit (courses / staff).
 * It renders itself as HTTP 402 (Payment Required) carrying an `upgrade_required`
 * hint, so any call site can simply `throw PlanLimitException::forResource(...)`
 * without wiring a response, and the owner UI gets a machine-readable reason to
 * prompt an upgrade.
 *
 * Why 402 and not 403: the owner portal (like most token UIs) treats 401/403 as
 * "auth is gone" and bounces to the login screen. A plan-limit hit is NOT an auth
 * failure, so reusing 403 logged the owner out the moment they crossed a cap.
 * 402 is semantically correct here and, crucially, is NOT swept up by any
 * `status === 401 || status === 403` guard — the client intercepts it explicitly
 * and shows the upgrade prompt instead of destroying the session.
 *
 * The public student-enrolment path does NOT throw this (a visitor can't upgrade
 * anyone's plan) — it reads App\Support\PlanGate::studentLimitReached() and
 * surfaces its own neutral "institute is full" message instead.
 */
class PlanLimitException extends \Exception
{
    public function __construct(
        public readonly string $resource,
        public readonly ?int $limit,
        public readonly string $planName,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : "Plan limit reached for {$resource}.");
    }

    public static function forResource(string $resource, int $limit, Tenant $tenant): self
    {
        $planName = (string) data_get($tenant->planConfig(), 'name', ucfirst($tenant->planSlug()));

        $message = match ($resource) {
            'staff' => "You've reached your {$planName} plan limit of {$limit} staff member"
                . ($limit === 1 ? '' : 's') . '. Upgrade your plan to add more team members.',
            // PDF wording — the cap counts enrolled ("Active") students.
            'students' => "You've reached your Active Student limit."
                . ' Upgrade your plan to enrol more Students.',
            default => "You've reached your {$planName} plan limit of {$limit} courses."
                . ' Upgrade your plan to add more.',
        };

        return new self($resource, $limit, $planName, $message);
    }

    /**
     * A paid-feature gate hit (chat, ai_materials, pre_recorded_video,
     * admission_marketer, …). No numeric limit — the message names the feature
     * and prompts an upgrade. Still HTTP 402 + upgrade_required, so the owner
     * client's maybeUpgrade()/UpgradeModal handle it exactly like a cap hit.
     */
    public static function forFeature(string $feature, Tenant $tenant): self
    {
        $planName = (string) data_get($tenant->planConfig(), 'name', ucfirst($tenant->planSlug()));

        $labels = [
            'chat' => 'Group chat',
            'certificates' => 'Certificates',
            'live_classes' => 'Live classes',
            'pre_recorded_video' => 'Pre-recorded video lessons',
            'admission_marketer' => 'the Admission-Marketer Network',
            'remove_branding' => 'Removing the "Powered by Jorsas" badge',
            'advanced_analytics' => 'Advanced analytics',
            'advanced_reporting' => 'Advanced reporting & exports',
            'custom_domain' => 'A custom domain',
            'priority_support' => 'Priority support',
            'ai_materials' => 'AI-generated training materials',
            'api_access' => 'API access',
            'white_label' => 'Full white-label',
        ];

        $label = $labels[$feature] ?? str_replace('_', ' ', $feature);
        $starts = $label[0] ?? '';
        $capitalised = ctype_upper($starts) || $starts === '';

        $message = ($capitalised ? $label : ucfirst($label))
            . " isn't included in your {$planName} plan. Upgrade to unlock it.";

        return new self($feature, null, $planName, $message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'feature' => $this->resource,
            'limit' => $this->limit,
            'plan' => $this->planName,
            'upgrade_required' => true,
        ], 402);
    }
}
