<?php

use App\Http\Controllers\LmsIntakeController;
use App\Http\Controllers\Lms\AdminController;
use App\Http\Controllers\Auth\SignupController;
use Botble\Base\Facades\DashboardMenu;
use Illuminate\Support\Facades\Route;

// Register top-level LMS admin menu (groups links for LMS management).
// Gate via config(), not env(): under `php artisan config:cache` a runtime
// env() read returns the default (false), which would silently drop this menu
// on production even when LMS_FEATURE_ENABLED=true. config/saas.php captures the
// flag at build time, so config('saas.lms_feature_enabled') survives caching.
if (config('saas.lms_feature_enabled')) {
	DashboardMenu::default()->beforeRetrieving(function (): void {
		DashboardMenu::make()->registerItem([
			'id' => 'cms-core-lms',
			'priority' => 49,
			'name' => 'LMS',
			'icon' => 'ti ti-book',
			'url' => route('admin.lms.index'),
		]);
	});
}

// ─── Admin Web (auth required) ────────────────────────────────────
// tenant.primary binds JIT for the admin panel's TenantAware reads/writes,
// which live outside the tenant-resolving lms-api group.
Route::middleware(['auth', 'tenant.primary'])->prefix(env('ADMIN_DIR', 'admin'))->group(function (): void {
	Route::redirect('/training/registrations', '/' . trim(env('ADMIN_DIR', 'admin'), '/') . '/lms/intake');
	Route::get('/training/registrations/pending', [LmsIntakeController::class, 'pending']);
	Route::post('/training/registrations/{id}/approve', [LmsIntakeController::class, 'approve']);
	Route::post('/training/registrations/{id}/decline', [LmsIntakeController::class, 'decline']);
	Route::post('/training/registrations/{id}/delete', [LmsIntakeController::class, 'destroy']);

	Route::get('/lms/intake', [LmsIntakeController::class, 'adminIndex'])->name('admin.lms.intake.index');
	Route::get('/lms/intake/pending', [LmsIntakeController::class, 'pending'])->name('admin.lms.intake.pending');
	Route::post('/lms/intake/{id}/approve', [LmsIntakeController::class, 'approve'])->name('admin.lms.intake.approve');
	Route::post('/lms/intake/{id}/decline', [LmsIntakeController::class, 'decline'])->name('admin.lms.intake.decline');
	Route::post('/lms/intake/{id}/delete', [LmsIntakeController::class, 'destroy'])->name('admin.lms.intake.delete');

	Route::get('/lms/courses', [AdminController::class, 'coursesPage'])->name('lms.courses.index');
	Route::post('/lms/courses', [AdminController::class, 'createCourse'])->name('lms.courses.store');
	Route::post('/lms/courses/{id}/delete', [AdminController::class, 'deleteCourse'])->name('lms.courses.delete');
	Route::get('/lms/teachers/create', [AdminController::class, 'createTeacherPage'])->name('lms.teachers.create');
	Route::post('/lms/teachers', [AdminController::class, 'createTeacher'])->name('lms.teachers.store');
	Route::get('/lms/classrooms', [AdminController::class, 'classroomsPage'])->name('lms.classrooms.index');
	Route::post('/lms/classrooms', [AdminController::class, 'createClassroom'])->name('lms.classrooms.store');
	Route::post('/lms/classrooms/{id}/delete', [AdminController::class, 'deleteClassroom'])->name('lms.classrooms.delete');
	Route::get('/lms/tracks', [AdminController::class, 'tracksPage'])->name('lms.tracks.index');
	Route::post('/lms/tracks', [AdminController::class, 'createTrack'])->name('lms.tracks.store');
	Route::post('/lms/tracks/{id}/delete', [AdminController::class, 'deleteTrack'])->name('lms.tracks.delete');
	Route::post('/lms/enrollments', [AdminController::class, 'enrollStudent']);
	Route::get('/lms/students', [AdminController::class, 'studentsPage'])->name('admin.lms.students.index');
	Route::post('/lms/students/{id}/delete', [AdminController::class, 'deleteStudent'])->name('admin.lms.students.delete');
	Route::get('/lms/agents', [AdminController::class, 'agentsPage'])->name('admin.lms.agents.index');
	Route::post('/lms/agents/{id}/approve', [AdminController::class, 'approveAgent'])->name('admin.lms.agents.approve');
	Route::post('/lms/agents/{id}/reject', [AdminController::class, 'rejectAgent'])->name('admin.lms.agents.reject');
	Route::post('/lms/agents/{id}/delete', [AdminController::class, 'deleteAgent'])->name('admin.lms.agents.delete');
	Route::get('/lms/agents/withdrawals', [AdminController::class, 'withdrawalRequestsPage'])->name('admin.lms.agents.withdrawals');
	Route::post('/lms/agents/{agentId}/pay', [AdminController::class, 'payCommissions'])->name('admin.lms.agents.pay');
	Route::get('/lms', [AdminController::class, 'index'])->name('admin.lms.index');
	Route::get('/lms/institutes', [AdminController::class, 'institutesPage'])->name('admin.lms.institutes.index');
	Route::post('/lms/institutes/{id}/delete', [AdminController::class, 'deleteInstitute'])->name('admin.lms.institutes.delete');
	Route::post('/lms/institutes/{id}/resend', [AdminController::class, 'resendInstituteOnboarding'])->name('admin.lms.institutes.resend');

	// Platform-wide announcements (host → every institute's students/staff/agents)
	Route::get('/lms/announcements', [AdminController::class, 'platformAnnouncementsPage'])->name('admin.lms.announcements.index');
	Route::post('/lms/announcements', [AdminController::class, 'createPlatformAnnouncement'])->name('admin.lms.announcements.store');

	// CEO's Forum (host schedules live meetings for every institute owner)
	Route::get('/lms/forums', [AdminController::class, 'ceoForumsPage'])->name('admin.lms.forums.index');
	Route::post('/lms/forums', [AdminController::class, 'createCeoForum'])->name('admin.lms.forums.store');
	Route::post('/lms/forums/{id}/update', [AdminController::class, 'updateCeoForum'])->name('admin.lms.forums.update');
	Route::post('/lms/forums/{id}/cancel', [AdminController::class, 'cancelCeoForum'])->name('admin.lms.forums.cancel');

	// Platform revenue ledger (institute→platform subscription payments + live per-institute course earnings/commission)
	Route::get('/lms/transactions', [AdminController::class, 'transactionsPage'])->name('admin.lms.transactions.index');

	// Plans admin
	Route::get('/plans', [\App\Http\Controllers\Admin\PlanController::class, 'index'])->name('admin.plans.index');
	Route::post('/plans', [\App\Http\Controllers\Admin\PlanController::class, 'store'])->name('admin.plans.store');
	Route::post('/plans/{id}/delete', [\App\Http\Controllers\Admin\PlanController::class, 'destroy'])->name('admin.plans.delete');
});

// Public signup route moved to API routes to ensure JSON responses and avoid
// CSRF/web redirects during XHR from the Next.js frontend.
// Route::post('/api/signup', [SignupController::class, 'signup']);

// Public test screens
use App\Http\Controllers\PublicPagesController;
Route::get('/signup', [PublicPagesController::class, 'signup'])->name('public.signup');
Route::get('/plans', [PublicPagesController::class, 'plans'])->name('public.plans');
Route::get('/onboarding-status', [PublicPagesController::class, 'onboardingStatus'])->name('public.onboarding');

// API: plans list for frontend
Route::get('/api/plans', [PublicPagesController::class, 'plansJson']);
