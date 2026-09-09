<?php

use App\Http\Controllers\FrontendContentController;
use App\Http\Controllers\LmsIntakeController;
use App\Http\Controllers\PublicInstituteController;
use App\Http\Controllers\Lms\TenantBillingController;
use App\Http\Controllers\Lms\OwnerAdminController;
use App\Http\Controllers\Lms\OwnerAgentController;
use App\Http\Controllers\Lms\OwnerGammaController;
use App\Http\Controllers\Lms\StudentAuthController;
use App\Http\Controllers\Lms\StudentProfileController;
use App\Http\Controllers\Lms\StudentDashboardController;
use App\Http\Controllers\Lms\StudentClassroomController;
use App\Http\Controllers\Lms\StudentChatController;
use App\Http\Controllers\Lms\StudentMaterialController;
use App\Http\Controllers\Lms\StudentModuleController;
use App\Http\Controllers\Lms\StudentCourseReviewController;
use App\Http\Controllers\Lms\StaffAuthController;
use App\Http\Controllers\Lms\StaffGammaController;
use App\Http\Controllers\Lms\StaffTaskController;
use App\Http\Controllers\Lms\StaffChatController;
use App\Http\Controllers\Lms\StaffClassroomController;
use App\Http\Controllers\Lms\StaffProfileController;
use App\Http\Controllers\Lms\StaffModuleController;
use App\Http\Controllers\Lms\StaffVideoController;
use App\Http\Controllers\Lms\StaffPortalController;
use App\Http\Controllers\Lms\StaffNotificationController;
use App\Http\Controllers\Lms\AdminController;
use App\Http\Controllers\Lms\AgentController;
use App\Http\Controllers\Lms\BroadcastingAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform / public / webhook / auth-bootstrap routes (NO tenant required)
|--------------------------------------------------------------------------
| These either operate above any single tenant (signup, plan/tenant lookup,
| webhooks), are self-authorizing via their own token, or establish tenancy
| from a payload/invite. They still receive tenant *resolution* (via header /
| subdomain) but must never fail closed on a missing tenant.
*/

Route::get('/api/frontend/home-content', [FrontendContentController::class, 'home']);

// Institute / course catalog (public)
Route::get('/api/frontend/institute/courses', [LmsIntakeController::class, 'courseCatalog']);
Route::get('/api/frontend/institute/courses/{slug}', [LmsIntakeController::class, 'courseDetail']);

// Per-institute public storefront (tenant scoped from the URL). The apex
// /institute page uses the primary-* endpoints (Tenant::primary()); each
// institute's own mini-site uses /i/{slug}. Both return only that institute's
// active courses + its white-label branding.
Route::get('/api/frontend/institute/primary', [PublicInstituteController::class, 'primaryShow']);
Route::get('/api/frontend/institute/primary/courses/{courseSlug}', [PublicInstituteController::class, 'primaryCourse']);
Route::get('/api/frontend/i/{slug}', [PublicInstituteController::class, 'show']);
Route::get('/api/frontend/i/{slug}/courses/{courseSlug}', [PublicInstituteController::class, 'course']);

// "Campuses" directory (jorsastech nav): every Pro-and-above academy as an
// avatar card. Cross-tenant showcase — see PublicInstituteController::campuses.
Route::get('/api/frontend/campuses', [PublicInstituteController::class, 'campuses']);

// Tenant resolution for frontends (by host or slug)
Route::get('/api/tenant/resolve', [\App\Http\Controllers\PublicPagesController::class, 'resolveTenant']);

// Onboarding status API
Route::get('/api/onboarding-status', [\App\Http\Controllers\PublicPagesController::class, 'onboardingStatusJson']);
// Owner summary (tenant-level) for frontend owner dashboard — self-authorizing via its own token
Route::get('/api/frontend/lms/owner-summary', [\App\Http\Controllers\PublicPagesController::class, 'ownerSummary']);

// Registration + payment flow
Route::post('/api/frontend/training/register', [LmsIntakeController::class, 'register'])->middleware('throttle:10,1');
Route::post('/api/frontend/paystack/initialize', [LmsIntakeController::class, 'initializePayment']);
Route::get('/api/frontend/paystack/verify', [LmsIntakeController::class, 'verifyPayment']);
Route::post('/api/frontend/paystack/webhook', [LmsIntakeController::class, 'webhook']);
// Generic Paystack webhook endpoint for subscriptions and transactions
Route::post('/api/paystack/webhook', [\App\Http\Controllers\PaystackWebhookController::class, 'handle']);

// Note: signup route moved to web.php to avoid tenant resolution middleware

// Tenant signup (SaaS onboarding)
Route::post('/api/signup', [\App\Http\Controllers\TenantSignupController::class, 'signup'])->middleware('throttle:5,1');
// Pay-first signup: confirm payment, then activate + provision the tenant (public callback)
Route::get('/api/signup/verify', [\App\Http\Controllers\TenantSignupController::class, 'verify'])->middleware('throttle:30,1');

// Student Auth (public bootstrap: invite, public course list, signup, password flows)
Route::get('/api/frontend/lms/invite', [StudentAuthController::class, 'invite']);
Route::get('/api/frontend/lms/courses', [StudentAuthController::class, 'courses']);
Route::post('/api/frontend/lms/signup', [StudentAuthController::class, 'signup'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/setup-password', [StudentAuthController::class, 'setupPassword'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/forgot-password', [StudentAuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/reset-password', [StudentAuthController::class, 'resetPassword'])->middleware('throttle:10,1');

// Institute branding for UNAUTHENTICATED pages (login / password setup / reset):
// themes the shell before any portal session exists. Tenant resolved from the
// invite/setup token or subdomain/header (see BrandingController::publicShow).
Route::get('/api/frontend/lms/branding/public', [\App\Http\Controllers\Lms\BrandingController::class, 'publicShow'])->middleware('throttle:60,1');

// Owner invite / setup / login (tenant established from invite/payload)
Route::get('/api/frontend/lms/owner-invite', [\App\Http\Controllers\Lms\OwnerAuthController::class, 'invite']);
Route::post('/api/frontend/lms/owner-setup', [\App\Http\Controllers\Lms\OwnerAuthController::class, 'setup'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/owner-login', [\App\Http\Controllers\Lms\OwnerAuthController::class, 'login'])->middleware('throttle:10,1');

// Staff Auth (public password flows)
Route::post('/api/frontend/lms/staff/forgot-password', [StaffAuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/staff/reset-password', [StaffAuthController::class, 'resetPassword'])->middleware('throttle:10,1');

// Agent Auth (public bootstrap) + public course list
Route::post('/api/frontend/lms/agents/apply', [AgentController::class, 'apply']);
Route::post('/api/frontend/lms/agents/login', [AgentController::class, 'login']);
Route::post('/api/frontend/lms/agents/forgot-password', [AgentController::class, 'forgotPassword']);
Route::post('/api/frontend/lms/agents/reset-password', [AgentController::class, 'resetPassword']);
Route::get('/api/frontend/lms/agents/courses', [AgentController::class, 'courses']);

/*
| Admin API (web super-admin auth, not LmsSession) — bound to the primary
| tenant rather than gated by tenant.required. These authorize via
| $request->user() and carry no LmsSession bearer, so session-based tenant
| binding does not apply; instead tenant.primary binds JIT so their TenantAware
| reads/writes scope to the sole organisation instead of failing closed.
| Platform-admin-vs-org-owner separation is a later phase (see plan §5).
*/
Route::middleware('tenant.primary')->group(function () {
    Route::get('/api/frontend/lms/admin/tracks', [AdminController::class, 'listTracks']);
    Route::post('/api/frontend/lms/admin/tracks', [AdminController::class, 'apiCreateTrack']);
    Route::put('/api/frontend/lms/admin/tracks/{id}', [AdminController::class, 'updateTrack']);
    Route::delete('/api/frontend/lms/admin/tracks/{id}', [AdminController::class, 'deleteTrackApi']);
    Route::get('/api/frontend/lms/admin/batches', [AdminController::class, 'listBatches']);
    Route::post('/api/frontend/lms/admin/batches', [AdminController::class, 'createBatch']);
    Route::post('/api/frontend/lms/admin/batches/{id}/announcements', [AdminController::class, 'createBatchAnnouncement']);

    Route::get('/api/frontend/lms/admin/agents/pending', [AgentController::class, 'adminPending']);
    Route::get('/api/frontend/lms/admin/agents', [AgentController::class, 'adminAll']);
    Route::post('/api/frontend/lms/admin/agents/{id}/approve', [AgentController::class, 'adminApprove']);
    Route::post('/api/frontend/lms/admin/agents/{id}/reject', [AgentController::class, 'adminReject']);

    // Backwards-compatible routes (legacy frontend expected paths)
    Route::get('/api/lms/admin/tracks', [AdminController::class, 'listTracks']);
    Route::post('/api/lms/admin/tracks', [AdminController::class, 'apiCreateTrack']);
    Route::put('/api/lms/admin/tracks/{id}', [AdminController::class, 'updateTrack']);
    Route::delete('/api/lms/admin/tracks/{id}', [AdminController::class, 'deleteTrackApi']);

    Route::get('/api/lms/admin/batches', [AdminController::class, 'listBatches']);
    Route::post('/api/lms/admin/batches', [AdminController::class, 'createBatch']);
    Route::post('/api/lms/admin/batches/{id}/announcements', [AdminController::class, 'createBatchAnnouncement']);

    Route::post('/api/lms/admin/agents/{id}/approve', [AgentController::class, 'adminApprove']);
    Route::post('/api/lms/admin/agents/{id}/reject', [AgentController::class, 'adminReject']);
    Route::get('/api/lms/admin/agents/pending', [AgentController::class, 'adminPending']);
    Route::get('/api/lms/admin/agents', [AgentController::class, 'adminAll']);
});

/*
|--------------------------------------------------------------------------
| Tenant-scoped routes (tenant REQUIRED once enforcement is enabled)
|--------------------------------------------------------------------------
| Authenticated student/staff/agent/owner routes. The tenant is derived from
| the session (ResolveTenantFromSession); email/password logins require the
| tenant from a header/subdomain at entry. RequireTenant aborts 400 when no
| tenant is bound (only once config('saas.enforce_tenancy') is true).
*/
Route::middleware(['tenant.required', 'subscription.gate'])->group(function () {
    // Owner onboarding actions (org update, import students, create course, invite staff)
    Route::post('/api/frontend/lms/onboarding/org', [\App\Http\Controllers\Lms\OwnerOnboardingController::class, 'updateOrg']);
    Route::post('/api/frontend/lms/onboarding/import-students', [\App\Http\Controllers\Lms\OwnerOnboardingController::class, 'importStudents']);
    Route::post('/api/frontend/lms/onboarding/create-course', [\App\Http\Controllers\Lms\OwnerOnboardingController::class, 'createCourse']);
    Route::post('/api/frontend/lms/onboarding/invite-staff', [\App\Http\Controllers\Lms\OwnerOnboardingController::class, 'inviteStaff']);

    // Owner billing (self-serve Paystack plan upgrades — authorizes via owner session)
    Route::get('/api/frontend/lms/billing/status', [TenantBillingController::class, 'status']);
    Route::post('/api/frontend/lms/billing/checkout', [TenantBillingController::class, 'checkout'])->middleware('throttle:10,1');
    Route::get('/api/frontend/lms/billing/verify', [TenantBillingController::class, 'verify']);

    // Owner admin dashboard (read-only lists — authorizes via owner session + tenant_admins)
    Route::get('/api/frontend/lms/owner/overview', [OwnerAdminController::class, 'overview']);
    Route::get('/api/frontend/lms/owner/analytics', [OwnerAdminController::class, 'analytics']);
    Route::get('/api/frontend/lms/owner/students', [OwnerAdminController::class, 'students']);
    Route::get('/api/frontend/lms/owner/staff', [OwnerAdminController::class, 'staff']);
    Route::get('/api/frontend/lms/owner/courses', [OwnerAdminController::class, 'courses']);
    Route::get('/api/frontend/lms/owner/tracks', [OwnerAdminController::class, 'tracks']);
    Route::get('/api/frontend/lms/owner/notifications', [OwnerAdminController::class, 'notifications']);

    // CEO's Forum (platform-hosted live meetings for institute owners): list the
    // upcoming/past sessions, and mint a participant token to join in-portal.
    Route::get('/api/frontend/lms/owner/forums', [OwnerAdminController::class, 'forums']);
    Route::post('/api/frontend/lms/owner/forums/{id}/token', [OwnerAdminController::class, 'forumToken']);

    // Owner course management (create / edit / delete — set description, price,
    // capacity, delivery mode). Tenant-scoped writes, authorized via owner session.
    Route::post('/api/frontend/lms/owner/courses', [OwnerAdminController::class, 'storeCourse']);
    Route::put('/api/frontend/lms/owner/courses/{id}', [OwnerAdminController::class, 'updateCourse']);
    Route::delete('/api/frontend/lms/owner/courses/{id}', [OwnerAdminController::class, 'destroyCourse']);
    // Course storefront cover image (upload / remove) — multipart, owner-scoped.
    Route::post('/api/frontend/lms/owner/courses/{id}/cover', [OwnerAdminController::class, 'uploadCourseCover']);

    // Owner student management (remove a student, re-send a set-password invite).
    // Tenant-scoped: findOrFail resolves only within the owner's institute.
    Route::delete('/api/frontend/lms/owner/students/{id}', [OwnerAdminController::class, 'destroyStudent']);
    Route::post('/api/frontend/lms/owner/students/{id}/resend-invite', [OwnerAdminController::class, 'resendStudentInvite']);
    // Owner course invite: attach a student to a specific course — paid via the
    // academy's own Paystack (pay-first, provisioned on confirmation) or comped
    // straight in. Distinct from the bulk importer above (course-less accounts).
    Route::post('/api/frontend/lms/owner/students/invite', [OwnerAdminController::class, 'inviteStudentToCourse']);

    // Owner staff management (remove an instructor, re-send their set-password
    // invite, suspend/re-enable portal access). Tenant-scoped like the student
    // block; remove is refused while the instructor still leads a cohort.
    Route::delete('/api/frontend/lms/owner/staff/{id}', [OwnerAdminController::class, 'destroyStaff']);
    Route::post('/api/frontend/lms/owner/staff/{id}/resend-invite', [OwnerAdminController::class, 'resendStaffInvite']);
    Route::post('/api/frontend/lms/owner/staff/{id}/active', [OwnerAdminController::class, 'setStaffActive']);

    // Owner Admission Marketer (agent) management: everyone advertising the
    // academy with their referral numbers and payout balance, plus
    // approve/reject. Admission-marketer network is Basic+, gated via 402 → the
    // owner UpgradeModal.
    Route::get('/api/frontend/lms/owner/agents', [OwnerAgentController::class, 'index']);
    Route::get('/api/frontend/lms/owner/agents/{id}', [OwnerAgentController::class, 'show']);
    Route::post('/api/frontend/lms/owner/agents/{id}/approve', [OwnerAgentController::class, 'approve']);
    Route::post('/api/frontend/lms/owner/agents/{id}/reject', [OwnerAgentController::class, 'reject']);


    // Owner cohort management (create a track + assign an instructor / reassign staff)
    Route::post('/api/frontend/lms/owner/tracks', [OwnerAdminController::class, 'storeTrack']);
    Route::put('/api/frontend/lms/owner/tracks/{id}', [OwnerAdminController::class, 'updateTrack']);

    // Owner certificates (institute-issued, admin-only — moved off the staff portal)
    Route::get('/api/frontend/lms/owner/certificates', [OwnerAdminController::class, 'certificates']);
    Route::post('/api/frontend/lms/owner/certificates', [OwnerAdminController::class, 'issueCertificate']);
    Route::delete('/api/frontend/lms/owner/certificates/{id}', [OwnerAdminController::class, 'revokeCertificate']);

    // Owner white-label branding (logo / colors / font)
    Route::get('/api/frontend/lms/owner/branding', [OwnerAdminController::class, 'branding']);
    Route::post('/api/frontend/lms/owner/branding', [OwnerAdminController::class, 'updateBranding']);
    Route::post('/api/frontend/lms/owner/branding/logo', [OwnerAdminController::class, 'uploadLogo']);

    // Owner public-page profile (hero/about/contact/socials + cover image) —
    // the content shown on the institute's own /i/{slug} storefront.
    Route::get('/api/frontend/lms/owner/profile', [OwnerAdminController::class, 'profile']);
    Route::post('/api/frontend/lms/owner/profile', [OwnerAdminController::class, 'updateProfile']);
    Route::post('/api/frontend/lms/owner/profile/cover', [OwnerAdminController::class, 'uploadCover']);

    // Owner's own LOGIN account (Profile page → Personal details): name, email
    // and password of the users-table row behind the owner session. Distinct
    // from the public-page profile above (storefront content).
    Route::get('/api/frontend/lms/owner/account', [OwnerAdminController::class, 'account']);
    Route::post('/api/frontend/lms/owner/account', [OwnerAdminController::class, 'updateAccount']);
    Route::post('/api/frontend/lms/owner/account/password', [OwnerAdminController::class, 'changeAccountPassword'])->middleware('throttle:10,1');

    // Owner course-fee payout: link the institute's own Paystack subaccount so
    // course fees settle to its bank (institute collects, not the platform).
    Route::get('/api/frontend/lms/owner/payment-settings', [OwnerAdminController::class, 'paymentSettings']);
    Route::post('/api/frontend/lms/owner/payment-settings', [OwnerAdminController::class, 'updatePaymentSettings']);
    // Confirm the account-holder name before linking (Paystack /bank/resolve).
    Route::post('/api/frontend/lms/owner/resolve-account', [OwnerAdminController::class, 'resolveBankAccount'])->middleware('throttle:30,1');

    // Owner custom domains (Pro/Enterprise): point learn.youracademy.com at the
    // platform. Add → publish DNS → verify (TXT check) → the domain resolves the
    // academy (ResolveTenant). Every action is tenant-scoped + custom_domain-gated
    // inside the controller. Verify is throttled (each call does a DNS lookup).
    Route::get('/api/frontend/lms/owner/domains', [OwnerAdminController::class, 'domains']);
    Route::post('/api/frontend/lms/owner/domains', [OwnerAdminController::class, 'addDomain']);
    Route::post('/api/frontend/lms/owner/domains/{id}/verify', [OwnerAdminController::class, 'verifyDomain'])->middleware('throttle:20,1');
    Route::delete('/api/frontend/lms/owner/domains/{id}', [OwnerAdminController::class, 'deleteDomain']);

    // Owner AI training materials (Gamma, Pro+). generate → poll status → save the
    // finished Gamma link into a course/module. Every action gates on the
    // ai_materials feature inside the controller (PlanGate → 402 → UpgradeModal),
    // so the routes stay open here and a non-Pro academy simply gets the upgrade
    // prompt. Generate is throttled — each call spends Gamma credits.
    Route::post('/api/frontend/lms/owner/ai/materials/generate', [OwnerGammaController::class, 'generate'])->middleware('throttle:20,1');
    Route::get('/api/frontend/lms/owner/ai/materials/{id}', [OwnerGammaController::class, 'status']);
    Route::post('/api/frontend/lms/owner/ai/materials/save', [OwnerGammaController::class, 'save']);
    // Modules of one owner course — populates the AI-materials "save into module"
    // picker. Tenant-scoped + ai_materials-gated inside the controller.
    Route::get('/api/frontend/lms/owner/courses/{course}/modules', [OwnerGammaController::class, 'courseModules']);

    // Portal-agnostic branding read: student & staff shells theme themselves
    // to match the owner's customization (tenant resolved from their session).
    Route::get('/api/frontend/lms/branding', [\App\Http\Controllers\Lms\BrandingController::class, 'show']);

    // Student Auth (login — tenant from header/subdomain at entry)
    Route::post('/api/frontend/lms/login', [StudentAuthController::class, 'login'])->middleware('throttle:10,1');

    // Student Profile
    Route::get('/api/frontend/lms/me', [StudentProfileController::class, 'me']);
    Route::get('/api/frontend/lms/profile', [StudentProfileController::class, 'profile']);
    Route::post('/api/frontend/lms/profile', [StudentProfileController::class, 'updateProfile']);
    Route::post('/api/frontend/lms/profile/password', [StudentProfileController::class, 'changePassword']);
    Route::post('/api/frontend/lms/profile/photo', [StudentProfileController::class, 'uploadPhoto']);
    Route::get('/api/frontend/lms/certificates', [StudentProfileController::class, 'certificates']);

    // Student Dashboard
    Route::get('/api/frontend/lms/dashboard', [StudentDashboardController::class, 'dashboard']);
    Route::get('/api/frontend/lms/tasks', [StudentDashboardController::class, 'tasks']);
    Route::get('/api/frontend/lms/tasks/{id}', [StudentDashboardController::class, 'taskDetail']);
    Route::post('/api/frontend/lms/tasks/{id}/submit', [StudentDashboardController::class, 'submitTask']);
    Route::get('/api/frontend/lms/notifications', [StudentDashboardController::class, 'notifications']);
    Route::post('/api/frontend/lms/notifications/{id}/read', [StudentDashboardController::class, 'markNotificationRead']);
    Route::get('/api/frontend/lms/attendance', [StudentDashboardController::class, 'attendance']);

    // Student course rating (Udemy-style ★). Enrolled-only gate lives in the
    // controller; a student can only rate the course they're enrolled in. Kept
    // OUTSIDE plan.chat so ratings work on the free plan too.
    Route::post('/api/frontend/lms/courses/{id}/rate', [StudentCourseReviewController::class, 'store'])->middleware('throttle:30,1');

    // Student Classroom
    Route::post('/api/frontend/lms/classrooms/{id}/join', [StudentClassroomController::class, 'join']);
    Route::get('/api/frontend/lms/classrooms/{id}/launch', [StudentClassroomController::class, 'launch']);
    Route::post('/api/frontend/lms/classrooms/{id}/sdk-signature', [StudentClassroomController::class, 'sdkSignature']);
    // Client-side attendance close-out (replaces the old Zoom meeting.ended webhook):
    // the student portal posts this when the embedded Jitsi room tears down.
    Route::post('/api/frontend/lms/classrooms/{id}/attendance-leave', [StudentClassroomController::class, 'attendanceLeave']);

    // Student Materials
    Route::get('/api/frontend/lms/materials', [StudentMaterialController::class, 'index']);

    // Broadcasting Auth (WebSocket)
    Route::post('/api/frontend/lms/broadcasting/auth', [BroadcastingAuthController::class, 'auth']);

    // Student Chat — messaging is a paid-plan feature (plan.chat). The unread
    // endpoint stays open because it also carries notification counts the free
    // portal still needs.
    Route::middleware('plan.chat')->group(function () {
        Route::get('/api/frontend/lms/messages', [StudentChatController::class, 'messages']);
        Route::post('/api/frontend/lms/messages', [StudentChatController::class, 'sendMessage']);
        Route::get('/api/frontend/lms/chats/bootstrap', [StudentChatController::class, 'chatBootstrap']);
        Route::get('/api/frontend/lms/chats/group/messages', [StudentChatController::class, 'groupMessages']);
        Route::post('/api/frontend/lms/chats/group/messages', [StudentChatController::class, 'sendGroupMessage']);
        Route::post('/api/frontend/lms/chats/group/messages/{id}/delete', [StudentChatController::class, 'deleteGroupMessage']);
        Route::put('/api/frontend/lms/chats/group/messages/{id}', [StudentChatController::class, 'editGroupMessage']);
        Route::get('/api/frontend/lms/chats/group/mentionable', [StudentChatController::class, 'mentionableUsers']);
        Route::get('/api/frontend/lms/chats/dm/messages', [StudentChatController::class, 'dmMessages']);
        Route::post('/api/frontend/lms/chats/dm/messages', [StudentChatController::class, 'sendDmMessage']);
        Route::post('/api/frontend/lms/chats/dm/messages/{id}/delete', [StudentChatController::class, 'deleteDmMessage']);
        Route::put('/api/frontend/lms/chats/dm/messages/{id}', [StudentChatController::class, 'editDmMessage']);
        // Reactions — one endpoint for both group & DM; the controller resolves
        // the chat from the message id.
        Route::post('/api/frontend/lms/chats/messages/{id}/react', [StudentChatController::class, 'toggleReaction']);
        Route::post('/api/frontend/lms/chats/group/read', [StudentChatController::class, 'markGroupRead']);
        Route::post('/api/frontend/lms/chats/dm/read', [StudentChatController::class, 'markDmRead']);
    });
    Route::get('/api/frontend/lms/chats/unread', [StudentChatController::class, 'unreadCount']);

    // Staff Auth (login — tenant from header/subdomain at entry) + authenticated staff identity
    Route::post('/api/frontend/lms/staff/login', [StaffAuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/api/frontend/lms/staff/dashboard', [StaffAuthController::class, 'dashboard']);
    Route::get('/api/frontend/lms/staff/me', [StaffAuthController::class, 'me']);

    // Staff Tasks
    Route::post('/api/frontend/lms/staff/tasks', [StaffTaskController::class, 'createTask']);
    Route::post('/api/frontend/lms/staff/tasks/{taskId}/submissions/{submissionId}/grade', [StaffTaskController::class, 'gradeSubmission']);

    // Staff Chat — messaging gated to paid plans (plan.chat); unread stays open.
    Route::middleware('plan.chat')->group(function () {
        Route::get('/api/frontend/lms/staff/chats/group/messages', [StaffChatController::class, 'groupMessages']);
        Route::post('/api/frontend/lms/staff/chats/group/messages', [StaffChatController::class, 'sendGroupMessage']);
        Route::post('/api/frontend/lms/staff/chats/group/messages/{id}/delete', [StaffChatController::class, 'deleteGroupMessage']);
        Route::put('/api/frontend/lms/staff/chats/group/messages/{id}', [StaffChatController::class, 'editGroupMessage']);
        Route::post('/api/frontend/lms/staff/chats/dm/messages/{id}/delete', [StaffChatController::class, 'deleteDmMessage']);
        Route::put('/api/frontend/lms/staff/chats/dm/messages/{id}', [StaffChatController::class, 'editDmMessage']);
        Route::get('/api/frontend/lms/staff/chats/group/mentionable', [StaffChatController::class, 'mentionableUsers']);
        Route::get('/api/frontend/lms/staff/chats/dm/messages', [StaffChatController::class, 'dmMessages']);
        Route::post('/api/frontend/lms/staff/chats/dm/messages', [StaffChatController::class, 'sendDmMessage']);
        // Reactions — one endpoint for both group & DM; the controller resolves
        // the chat from the message id.
        Route::post('/api/frontend/lms/staff/chats/messages/{id}/react', [StaffChatController::class, 'toggleReaction']);
        Route::post('/api/frontend/lms/staff/chats/group/read', [StaffChatController::class, 'markGroupRead']);
        Route::post('/api/frontend/lms/staff/chats/dm/read', [StaffChatController::class, 'markDmRead']);
    });
    Route::get('/api/frontend/lms/staff/chats/unread', [StaffChatController::class, 'unreadCount']);

    // Staff Classroom
    Route::get('/api/frontend/lms/staff/classrooms', [StaffClassroomController::class, 'index']);
    Route::get('/api/frontend/lms/staff/classrooms/{id}', [StaffClassroomController::class, 'show']);
    Route::post('/api/frontend/lms/staff/classrooms', [StaffClassroomController::class, 'createClassroom']);
    Route::put('/api/frontend/lms/staff/classrooms/{id}', [StaffClassroomController::class, 'updateClassroom']);
    Route::delete('/api/frontend/lms/staff/classrooms/{id}', [StaffClassroomController::class, 'deleteClassroom']);
    // Moderator token so the instructor can host the live Jitsi room (both live-class
    // models via ?class_type=scheduled|classroom).
    Route::post('/api/frontend/lms/staff/classrooms/{id}/meeting-token', [StaffClassroomController::class, 'meetingToken']);

    // Staff Tasks (list)
    Route::get('/api/frontend/lms/staff/tasks', [StaffPortalController::class, 'listTasks']);
    Route::get('/api/frontend/lms/staff/tasks/{id}', [StaffPortalController::class, 'showTask']);

    // Staff Profile
    Route::get('/api/frontend/lms/staff/profile', [StaffProfileController::class, 'show']);
    Route::post('/api/frontend/lms/staff/profile', [StaffProfileController::class, 'update']);
    Route::post('/api/frontend/lms/staff/profile/password', [StaffProfileController::class, 'changePassword']);
    Route::post('/api/frontend/lms/staff/profile/photo', [StaffProfileController::class, 'uploadPhoto']);

    // Staff Materials
    Route::get('/api/frontend/lms/staff/materials', [StaffPortalController::class, 'materials']);
    Route::post('/api/frontend/lms/staff/materials', [StaffPortalController::class, 'storeMaterial']);
    Route::delete('/api/frontend/lms/staff/materials/{id}', [StaffPortalController::class, 'deleteMaterial']);

    // Staff Modules
    Route::get('/api/frontend/lms/staff/modules', [StaffModuleController::class, 'index']);
    Route::post('/api/frontend/lms/staff/modules', [StaffModuleController::class, 'store']);
    Route::get('/api/frontend/lms/staff/modules/{id}', [StaffModuleController::class, 'show']);
    Route::put('/api/frontend/lms/staff/modules/{id}', [StaffModuleController::class, 'update']);
    Route::delete('/api/frontend/lms/staff/modules/{id}', [StaffModuleController::class, 'destroy']);
    Route::post('/api/frontend/lms/staff/modules/{moduleId}/contents', [StaffModuleController::class, 'addContent']);
    Route::put('/api/frontend/lms/staff/modules/{moduleId}/contents/{contentId}', [StaffModuleController::class, 'updateContent']);
    Route::delete('/api/frontend/lms/staff/modules/{moduleId}/contents/{contentId}', [StaffModuleController::class, 'removeContent']);
    Route::post('/api/frontend/lms/staff/modules/{moduleId}/contents/upload', [StaffModuleController::class, 'uploadContentFile']);
    Route::put('/api/frontend/lms/staff/modules/{moduleId}/contents/reorder', [StaffModuleController::class, 'reorderContents']);
    Route::post('/api/frontend/lms/staff/modules/{moduleId}/schedule', [StaffModuleController::class, 'scheduleClass']);
    Route::get('/api/frontend/lms/staff/scheduled-classes', [StaffModuleController::class, 'classes']);
    Route::put('/api/frontend/lms/staff/scheduled-classes/{classId}', [StaffModuleController::class, 'updateClass']);
    Route::delete('/api/frontend/lms/staff/scheduled-classes/{classId}', [StaffModuleController::class, 'destroyClass']);

    // Staff pre-recorded video (Bunny Stream) — mint a signed direct-upload
    // envelope (the browser uploads the bytes straight to Bunny) + poll status.
    // Plan-gated on `pre_recorded_video` (Basic+) inside the controller.
    Route::post('/api/frontend/lms/staff/videos/upload', [StaffVideoController::class, 'createUpload']);
    Route::get('/api/frontend/lms/staff/videos/{videoId}/status', [StaffVideoController::class, 'videoStatus']);

    // Student Modules & Timetable
    Route::get('/api/frontend/lms/modules', [StudentModuleController::class, 'index']);
    Route::get('/api/frontend/lms/modules/{id}', [StudentModuleController::class, 'show']);
    Route::get('/api/frontend/lms/timetable', [StudentModuleController::class, 'timetable']);

    // Staff Attendance
    Route::get('/api/frontend/lms/staff/attendance', [StaffPortalController::class, 'attendance']);

    // Staff Certificates
    Route::get('/api/frontend/lms/staff/certificates', [StaffPortalController::class, 'certificates']);
    Route::post('/api/frontend/lms/staff/certificates', [StaffPortalController::class, 'issueCertificate']);

    // Staff Announcements
    Route::get('/api/frontend/lms/staff/announcements', [StaffPortalController::class, 'announcements']);
    Route::post('/api/frontend/lms/staff/announcements', [StaffPortalController::class, 'createAnnouncement']);
    Route::delete('/api/frontend/lms/staff/announcements/{id}', [StaffPortalController::class, 'deleteAnnouncement']);

    // Staff Reports
    Route::get('/api/frontend/lms/staff/reports', [StaffPortalController::class, 'reports']);

    // Staff Students
    Route::get('/api/frontend/lms/staff/students', [StaffPortalController::class, 'students']);

    // Staff Assigned Courses & Tracks
    Route::get('/api/frontend/lms/staff/courses', [StaffPortalController::class, 'assignedCourses']);
    Route::get('/api/frontend/lms/staff/tracks', [StaffPortalController::class, 'assignedTracks']);

    // Staff AI training materials (Gamma, Pro+) — the same generate → poll →
    // save flow as the owner's, but StaffGammaController scopes every target to
    // the teacher's assigned courses. The ai_materials PlanGate (402) is
    // inherited on every action; the staff page shows an "ask your academy
    // owner to upgrade" note (staff cannot upgrade the plan themselves).
    // Generate is throttled — each call spends Gamma credits.
    Route::post('/api/frontend/lms/staff/ai/materials/generate', [StaffGammaController::class, 'generate'])->middleware('throttle:20,1');
    Route::get('/api/frontend/lms/staff/ai/materials/{id}', [StaffGammaController::class, 'status']);
    Route::post('/api/frontend/lms/staff/ai/materials/save', [StaffGammaController::class, 'save']);
    // Modules of one assigned course — populates the staff AI-materials
    // "save into module" picker (assigned-course-scoped inside the controller).
    Route::get('/api/frontend/lms/staff/courses/{course}/modules', [StaffGammaController::class, 'courseModules']);

    // Staff Notifications
    Route::get('/api/frontend/lms/staff/notifications', [StaffNotificationController::class, 'index']);
    Route::get('/api/frontend/lms/staff/notifications/unread', [StaffNotificationController::class, 'unreadCount']);
    Route::post('/api/frontend/lms/staff/notifications/{id}/read', [StaffNotificationController::class, 'markRead']);
    Route::post('/api/frontend/lms/staff/notifications/read-all', [StaffNotificationController::class, 'markAllRead']);

    // Agent (authenticated) routes
    Route::get('/api/frontend/lms/agents/me', [AgentController::class, 'me']);
    Route::put('/api/frontend/lms/agents/profile', [AgentController::class, 'updateProfile']);
    Route::post('/api/frontend/lms/agents/avatar', [AgentController::class, 'uploadAvatar']);
    Route::get('/api/frontend/lms/agents/dashboard', [AgentController::class, 'dashboard']);
    Route::post('/api/frontend/lms/agents/register-student', [AgentController::class, 'registerStudent']);
    Route::get('/api/frontend/lms/agents/commissions', [AgentController::class, 'commissions']);
    Route::get('/api/frontend/lms/agents/registrations', [AgentController::class, 'registrations']);
    Route::get('/api/frontend/lms/agents/withdrawals', [AgentController::class, 'withdrawalHistory']);
    Route::post('/api/frontend/lms/agents/withdrawals/request', [AgentController::class, 'requestWithdrawal']);
    Route::get('/api/frontend/lms/agents/notifications', [AgentController::class, 'notifications']);
    Route::get('/api/frontend/lms/agents/notifications/unread', [AgentController::class, 'unreadCount']);
    Route::post('/api/frontend/lms/agents/notifications/{id}/read', [AgentController::class, 'markNotificationRead']);
    Route::post('/api/frontend/lms/agents/notifications/read-all', [AgentController::class, 'markAllNotificationsRead']);
});
