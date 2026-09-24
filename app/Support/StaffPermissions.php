<?php

namespace App\Support;

/**
 * What each staff role may reach in the staff portal.
 *
 * FOUR PRESETS, NOT A PERMISSION LIST. The academy owner picks a role per staff
 * member and that is the whole model; there are no per-person overrides. That was
 * a deliberate scope decision — the staff portal has never had any authorization
 * at all, so the first job is a distinction that is simple enough to explain to an
 * academy owner in one sentence, not a matrix nobody will maintain.
 *
 * Sections map 1:1 onto the staff sidebar groups (see StaffSidebar.tsx), so the
 * backend and the navigation can never disagree about what a "section" is: this
 * list IS the contract, and /staff/me hands the resolved list to the client.
 *
 * Granularity is per SECTION, not per action. An instructor who can reach
 * `students` can do everything within Students. That is the stated limitation of
 * presets-only; narrowing it later means adding keys here, not reshaping storage.
 *
 * The catalogue lives in code rather than the database so that adding a section is
 * a deploy rather than a migration, and so a role name can never be typo'd into an
 * accidental grant — an unknown role resolves to no sections at all.
 */
class StaffPermissions
{
    /** The full section vocabulary. */
    public const SECTIONS = [
        'dashboard',
        'courses',
        'tracks',
        'students',
        'classroom',
        'timetable',
        'attendance',
        'leaderboard',
        'modules',
        'materials',
        'ai_materials',
        'tasks',
        'chats',
        'notifications',
        'announcements',
        'reports',
    ];

    /**
     * Role => sections. `null` means every section, resolved at call time rather
     * than frozen, so a new section is granted to admins automatically instead of
     * being silently withheld until someone remembers to update a list here.
     *
     * `assistant` is the narrow one: the front-desk role. They can see who is
     * enrolled and keep attendance, and they can talk to students, but they cannot
     * author courses, modules, materials or tasks, and they cannot see the
     * academy's reports.
     */
    public const ROLES = [
        'owner'      => null,
        'admin'      => null,
        'instructor' => [
            'dashboard', 'courses', 'tracks', 'students', 'classroom', 'timetable',
            'attendance', 'leaderboard', 'modules', 'materials', 'ai_materials',
            'tasks', 'chats', 'notifications', 'announcements',
        ],
        'assistant'  => [
            'dashboard', 'students', 'classroom', 'timetable', 'attendance',
            'chats', 'notifications',
        ],
    ];

    /** Human labels for the owner's role picker. */
    public const LABELS = [
        'owner'      => 'Owner',
        'admin'      => 'Administrator',
        'instructor' => 'Instructor',
        'assistant'  => 'Assistant',
    ];

    /**
     * One-line explanations, shown next to the picker on the Staff Accounts page.
     * The request that produced this feature was as much about telling people what
     * a setting MEANS as about enforcing it.
     */
    public const DESCRIPTIONS = [
        'owner'      => 'The account holder. Full access, including billing.',
        'admin'      => 'Full access to everything in the staff portal.',
        'instructor' => 'Teaching and content: courses, students, classes, materials and tasks. No academy reports.',
        'assistant'  => 'Front desk: sees students, keeps attendance and chats. Cannot create courses or materials.',
    ];

    /**
     * The roles an owner may assign, ready to render: `role => [label, description]`.
     *
     * `owner` is excluded — see isAssignable(). Served to the client by
     * GET /owner/staff so the Staff Accounts page never hard-codes the list or
     * paraphrases what a role means.
     */
    public static function assignable(): array
    {
        $roles = [];

        foreach (self::LABELS as $role => $label) {
            if (! self::isAssignable($role)) {
                continue;
            }

            $roles[$role] = [
                'label' => $label,
                'description' => self::DESCRIPTIONS[$role] ?? '',
                'sections' => self::sectionsFor($role),
            ];
        }

        return $roles;
    }

    public static function isValidRole(string $role): bool
    {
        return array_key_exists($role, self::ROLES);
    }

    /**
     * Whether an owner may SET this role on a staff member.
     *
     * Separate from isValidRole() because `owner` is a real role that no request
     * may assign: it is resolved from `lms_teachers.is_academy_owner`, and the
     * owner is the account holder. Without this split, `isValidRole('owner')`
     * being true would be enough for a crafted request to hand somebody the
     * academy.
     */
    public static function isAssignable(string $role): bool
    {
        return array_key_exists($role, self::ROLES) && $role !== 'owner';
    }

    /**
     * The sections a role may reach. An unknown role gets nothing, which is the
     * fail-closed direction: a bad value in the column locks a staffer out of the
     * portal rather than handing them the academy.
     *
     * @return array<int,string>
     */
    public static function sectionsFor(string $role): array
    {
        if (! self::isValidRole($role)) {
            return [];
        }

        return self::ROLES[$role] ?? self::SECTIONS;
    }

    public static function allows(string $role, string $section): bool
    {
        return in_array($section, self::sectionsFor($role), true);
    }

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? 'Instructor';
    }
}
