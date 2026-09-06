<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Builds absolute, tenant-pinned deep links into the Next.js LMS for
 * notifications, so tapping a notification email lands the recipient on the
 * right in-app page.
 *
 * The path map mirrors the frontend `notificationHref` maps (student
 * src/app/lms/app/notifications, staff src/app/lms/staff/notifications) so the
 * email link and the in-app click target agree. Every branch resolves to a
 * route that exists, unknown reference types fall back to the recipient's
 * notifications page, which always exists, so an emailed link can never 404.
 */
class NotificationLinks
{
    /**
     * @param string          $audience   'student' | 'staff' | 'agent'
     * @param string|null     $refType    the notification's reference_type
     * @param int|string|null $refId      the notification's reference_id
     * @param string|null     $tenantSlug recipient's tenant slug, added as ?tenant=
     */
    public static function forNotification(string $audience, ?string $refType, $refId = null, ?string $tenantSlug = null): string
    {
        $base = rtrim((string) config('saas.frontend_url'), '/');
        $url = $base . self::pathFor($audience, $refType, $refId);

        if ($tenantSlug) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . 'tenant=' . urlencode($tenantSlug);
        }

        return $url;
    }

    /** Frontend path for (audience, reference_type[, reference_id]). */
    public static function pathFor(string $audience, ?string $refType, $refId = null): string
    {
        $refType = (string) ($refType ?? '');
        $id = ($refId !== null && $refId !== '') ? rawurlencode((string) $refId) : null;

        return match ($audience) {
            'staff' => match ($refType) {
                'task', 'task_submission' => '/lms/staff/tasks',
                'group_chat' => '/lms/staff/chats',
                'scheduled_class' => '/lms/staff/timetable',
                default => '/lms/staff/notifications',
            },
            'agent' => match ($refType) {
                'registration' => '/lms/agent/registrations',
                default => '/lms/agent/notifications',
            },
            // student (default audience)
            default => match ($refType) {
                'task' => $id ? '/lms/tasks/' . $id : '/lms/app/tasks',
                'group_chat' => '/lms/app/chats',
                'scheduled_class' => '/lms/app/classroom',
                'module' => $id ? '/lms/app/modules/' . $id : '/lms/app/modules',
                'course' => '/lms/app/modules',
                default => '/lms/app/notifications',
            },
        };
    }

    /**
     * tenant_id => slug map, resolved once per process. Lets the email sweep
     * label a batch of notifications spanning many tenants without a query per row.
     *
     * @return array<int,string>
     */
    public static function tenantSlugMap(): array
    {
        static $map = null;

        if ($map === null) {
            $map = Tenant::query()->pluck('slug', 'id')->all();
        }

        return $map;
    }
}
