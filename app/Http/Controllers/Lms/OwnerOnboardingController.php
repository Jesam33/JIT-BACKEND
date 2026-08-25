<?php

namespace App\Http\Controllers\Lms;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\LmsPasswordResetMail;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

class OwnerOnboardingController extends BaseLmsController
{
    protected function authorizeOwnerForTenant(Request $request, Tenant $tenant): bool
    {
        $accessToken = $request->query('token') ?? $request->header('X-Lms-Token') ?? $request->bearerToken();
        if (! $accessToken) return false;

        $inv = \App\Models\OwnerInvitation::where('token', $accessToken)->where('tenant_id', $tenant->id)->first();
        if ($inv && (! $inv->expires_at || ! $inv->expires_at->isPast())) return true;

        $sess = \App\Models\LmsSession::where('token', $accessToken)->where('expires_at', '>', now())->first();
        if ($sess && in_array($sess->role, ['owner','admin'])) {
            $isAdmin = DB::table('tenant_admins')->where('tenant_id', $tenant->id)->where('user_id', $sess->user_id)->exists();
            return (bool) $isAdmin;
        }

        return false;
    }

    public function updateOrg(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant');
        $tenant = Tenant::find($tenantId);
        if (! $tenant) return response()->json(['message' => 'tenant required'], 400);

        if (! $this->authorizeOwnerForTenant($request, $tenant)) {
            return response()->json(['message' => 'not authorized'], 403);
        }

        $validated = $request->validate(['name' => ['required','string']]);
        $tenant->update(['name' => $validated['name']]);

        DB::table('tenant_onboarding_audits')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'payload' => json_encode(['action' => 'org_updated', 'name' => $validated['name']]),
            'status' => 'org_updated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function importStudents(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant');
        $tenant = Tenant::find($tenantId);
        if (! $tenant) return response()->json(['message' => 'tenant required'], 400);
        if (! $this->authorizeOwnerForTenant($request, $tenant)) return response()->json(['message' => 'not authorized'], 403);

        $emails = $request->input('emails', []);
        if (! is_array($emails)) $emails = [];

        // The owner's authority over $tenant is confirmed above, so bind it as the
        // current tenant: TenantAware then stamps each new LmsStudent (and the
        // password-reset token) with this organisation.
        app()->instance('currentTenant', $tenant);

        $created = 0;
        $invited = 0;
        $skipped = 0;
        $failed = [];

        foreach ($emails as $rawEmail) {
            $email = strtolower(trim((string) $rawEmail));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            $student = LmsStudent::query()->where('email', $email)->first();
            if (! $student) {
                $student = LmsStudent::query()->create([
                    'email' => $email,
                    'first_name' => Str::before($email, '@'),
                    'last_name' => '',
                    // Unusable placeholder — the invitee sets their real password
                    // via the emailed reset link. Random (and never disclosed) so
                    // the account cannot be signed into until then, and it satisfies
                    // the NOT NULL password column.
                    'password' => Hash::make(Str::random(40)),
                    'onboarding_completed' => false,
                ]);
                $created++;
            }

            // Reuse the student password-reset flow as a "set your password"
            // invite — the emailed link lands on the tenant's reset page, which
            // resolves the organisation from the subdomain/tenant header.
            try {
                $token = $this->createPasswordResetToken('student', $email);
                $link = $this->buildResetLink('student', $email, $token);
                Mail::to($email)->send(new LmsPasswordResetMail($student->first_name ?: 'there', 'Student Portal', $link));
                $invited++;
            } catch (\Throwable $e) {
                // The account was created; only delivery failed. Surface it so the
                // owner can fix mail settings and resend, rather than the failure
                // disappearing into the log while the UI reports success.
                $failed[] = $email;
                Log::warning('Failed sending student invite', ['email' => $email, 'err' => $e->getMessage()]);
            }
        }

        DB::table('tenant_onboarding_audits')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'payload' => json_encode(['action' => 'students_imported', 'created' => $created, 'invited' => $invited, 'skipped' => $skipped, 'failed' => count($failed)]),
            'status' => 'students_imported',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'imported' => $created,
            'invited' => $invited,
            'skipped' => $skipped,
            'failed' => count($failed),
            'failed_emails' => $failed,
        ]);
    }

    public function createCourse(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant');
        $tenant = Tenant::find($tenantId);
        if (! $tenant) return response()->json(['message' => 'tenant required'], 400);
        if (! $this->authorizeOwnerForTenant($request, $tenant)) return response()->json(['message' => 'not authorized'], 403);

        $validated = $request->validate(['title' => ['required','string'], 'description' => ['nullable','string']]);

        $course = new \App\Models\LmsCourse();
        $course->title = $validated['title'];
        $course->description = $validated['description'] ?? null;
        // assign tenant_id directly if column exists
        if (\Schema::hasColumn('lms_courses', 'tenant_id')) {
            $course->tenant_id = $tenant->id;
        }
        $course->save();

        DB::table('tenant_onboarding_audits')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'payload' => json_encode(['action' => 'course_created', 'course_id' => $course->id, 'title' => $course->title]),
            'status' => 'course_created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['course_id' => $course->id]);
    }

    public function inviteStaff(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant');
        $tenant = Tenant::find($tenantId);
        if (! $tenant) return response()->json(['message' => 'tenant required'], 400);
        if (! $this->authorizeOwnerForTenant($request, $tenant)) return response()->json(['message' => 'not authorized'], 403);

        $email = strtolower(trim((string) $request->input('email')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'valid email required'], 422);
        }

        // Confirmed owner of $tenant → bind it so the new teacher is stamped with
        // this organisation.
        app()->instance('currentTenant', $tenant);

        $teacher = LmsTeacher::query()->where('email', $email)->first();
        if (! $teacher) {
            $teacher = LmsTeacher::query()->create([
                'name' => Str::before($email, '@'),
                'email' => $email,
                'role' => 'teacher',
                // Unusable placeholder password (see importStudents): the invitee
                // sets a real one via the reset link. Random so Hash::check fails
                // until then, and it satisfies the NOT NULL password column.
                'password' => Hash::make(Str::random(40)),
                // Active on creation; they simply cannot sign in until they set a
                // password via the invite link.
                'is_active' => true,
            ]);
        }

        $emailSent = false;
        try {
            $token = $this->createPasswordResetToken('staff', $email);
            // Land the invitee on the staff activation (set-password) page, not
            // the login form — they have no credentials yet; the owner issues
            // access and they set their own password here.
            $link = $this->buildSetupLink('staff', $email, $token);
            Mail::to($email)->send(new LmsPasswordResetMail($teacher->name ?: 'there', 'Staff Portal', $link));
            $emailSent = true;
        } catch (\Throwable $e) {
            Log::warning('Failed sending staff invite', ['email' => $email, 'err' => $e->getMessage()]);
        }

        DB::table('tenant_onboarding_audits')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'payload' => json_encode(['action' => 'staff_invited', 'email' => $email, 'teacher_id' => $teacher->id, 'email_sent' => $emailSent]),
            'status' => 'staff_invited',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The account exists either way, so the caller still gets 200 and refreshes
        // its list — but we report honestly whether the invite email actually went
        // out. A failed send used to be swallowed into the log and shown as success.
        return response()->json([
            'invited' => $email,
            'teacher_id' => $teacher->id,
            'email_sent' => $emailSent,
            'message' => $emailSent
                ? "Invited {$email}. They'll get an email with a link to set their password."
                : "The staff account for {$email} was created, but the invite email could not be sent. Check your mail settings, then use Send invite again to resend.",
        ]);
    }
}
