<?php

use App\Http\Controllers\FrontendContentController;
use App\Http\Controllers\LmsIntakeController;
use App\Http\Controllers\Lms\StudentAuthController;
use App\Http\Controllers\Lms\StudentProfileController;
use App\Http\Controllers\Lms\StudentDashboardController;
use App\Http\Controllers\Lms\StudentClassroomController;
use App\Http\Controllers\Lms\StudentChatController;
use App\Http\Controllers\Lms\StudentMaterialController;
use App\Http\Controllers\Lms\StudentModuleController;
use App\Http\Controllers\Lms\StaffAuthController;
use App\Http\Controllers\Lms\StaffTaskController;
use App\Http\Controllers\Lms\StaffChatController;
use App\Http\Controllers\Lms\StaffClassroomController;
use App\Http\Controllers\Lms\StaffProfileController;
use App\Http\Controllers\Lms\StaffModuleController;
use App\Http\Controllers\Lms\StaffPortalController;
use App\Http\Controllers\Lms\StaffNotificationController;
use App\Http\Controllers\Lms\AdminController;
use App\Http\Controllers\Lms\AgentController;
use App\Http\Controllers\Lms\BroadcastingAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/api/frontend/home-content', [FrontendContentController::class, 'home']);

// Institute / course catalog
Route::get('/api/frontend/institute/courses', [LmsIntakeController::class, 'courseCatalog']);
Route::get('/api/frontend/institute/courses/{slug}', [LmsIntakeController::class, 'courseDetail']);

// Registration + payment flow
Route::post('/api/frontend/training/register', [LmsIntakeController::class, 'register'])->middleware('throttle:10,1');
Route::post('/api/frontend/paystack/initialize', [LmsIntakeController::class, 'initializePayment']);
Route::get('/api/frontend/paystack/verify', [LmsIntakeController::class, 'verifyPayment']);
Route::post('/api/frontend/paystack/webhook', [LmsIntakeController::class, 'webhook']);

// Student Auth
Route::get('/api/frontend/lms/invite', [StudentAuthController::class, 'invite']);
Route::get('/api/frontend/lms/courses', [StudentAuthController::class, 'courses']);
Route::post('/api/frontend/lms/signup', [StudentAuthController::class, 'signup'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/setup-password', [StudentAuthController::class, 'setupPassword'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/login', [StudentAuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/forgot-password', [StudentAuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/reset-password', [StudentAuthController::class, 'resetPassword'])->middleware('throttle:10,1');

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

// Student Classroom
Route::post('/api/frontend/lms/classrooms/{id}/join', [StudentClassroomController::class, 'join']);
Route::get('/api/frontend/lms/classrooms/{id}/launch', [StudentClassroomController::class, 'launch']);
Route::post('/api/frontend/lms/classrooms/{id}/sdk-signature', [StudentClassroomController::class, 'sdkSignature']);
Route::post('/api/frontend/lms/webhooks/zoom', [StudentClassroomController::class, 'zoomWebhook']);

// Student Materials
Route::get('/api/frontend/lms/materials', [StudentMaterialController::class, 'index']);

// Broadcasting Auth (WebSocket)
Route::post('/api/frontend/lms/broadcasting/auth', [BroadcastingAuthController::class, 'auth']);

// Student Chat
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
Route::get('/api/frontend/lms/chats/unread', [StudentChatController::class, 'unreadCount']);

// Staff Auth
Route::post('/api/frontend/lms/staff/login', [StaffAuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/staff/forgot-password', [StaffAuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
Route::post('/api/frontend/lms/staff/reset-password', [StaffAuthController::class, 'resetPassword'])->middleware('throttle:10,1');
Route::get('/api/frontend/lms/staff/dashboard', [StaffAuthController::class, 'dashboard']);
Route::get('/api/frontend/lms/staff/me', [StaffAuthController::class, 'me']);

// Staff Tasks
Route::post('/api/frontend/lms/staff/tasks', [StaffTaskController::class, 'createTask']);
Route::post('/api/frontend/lms/staff/tasks/{taskId}/submissions/{submissionId}/grade', [StaffTaskController::class, 'gradeSubmission']);

// Staff Chat
Route::get('/api/frontend/lms/staff/chats/group/messages', [StaffChatController::class, 'groupMessages']);
Route::post('/api/frontend/lms/staff/chats/group/messages', [StaffChatController::class, 'sendGroupMessage']);
Route::post('/api/frontend/lms/staff/chats/group/messages/{id}/delete', [StaffChatController::class, 'deleteGroupMessage']);
Route::put('/api/frontend/lms/staff/chats/group/messages/{id}', [StaffChatController::class, 'editGroupMessage']);
Route::post('/api/frontend/lms/staff/chats/dm/messages/{id}/delete', [StaffChatController::class, 'deleteDmMessage']);
Route::put('/api/frontend/lms/staff/chats/dm/messages/{id}', [StaffChatController::class, 'editDmMessage']);
Route::get('/api/frontend/lms/staff/chats/group/mentionable', [StaffChatController::class, 'mentionableUsers']);
Route::get('/api/frontend/lms/staff/chats/dm/messages', [StaffChatController::class, 'dmMessages']);
Route::post('/api/frontend/lms/staff/chats/dm/messages', [StaffChatController::class, 'sendDmMessage']);
Route::get('/api/frontend/lms/staff/chats/unread', [StaffChatController::class, 'unreadCount']);
Route::post('/api/frontend/lms/staff/chats/group/read', [StaffChatController::class, 'markGroupRead']);
Route::post('/api/frontend/lms/staff/chats/dm/read', [StaffChatController::class, 'markDmRead']);

// Staff Classroom
Route::get('/api/frontend/lms/staff/classrooms', [StaffClassroomController::class, 'index']);
Route::get('/api/frontend/lms/staff/classrooms/{id}', [StaffClassroomController::class, 'show']);
Route::post('/api/frontend/lms/staff/classrooms', [StaffClassroomController::class, 'createClassroom']);
Route::put('/api/frontend/lms/staff/classrooms/{id}', [StaffClassroomController::class, 'updateClassroom']);
Route::delete('/api/frontend/lms/staff/classrooms/{id}', [StaffClassroomController::class, 'deleteClassroom']);

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

// Staff Notifications
Route::get('/api/frontend/lms/staff/notifications', [StaffNotificationController::class, 'index']);
Route::get('/api/frontend/lms/staff/notifications/unread', [StaffNotificationController::class, 'unreadCount']);
Route::post('/api/frontend/lms/staff/notifications/{id}/read', [StaffNotificationController::class, 'markRead']);
Route::post('/api/frontend/lms/staff/notifications/read-all', [StaffNotificationController::class, 'markAllRead']);

// Admin API
Route::get('/api/frontend/lms/admin/tracks', [AdminController::class, 'listTracks']);
Route::post('/api/frontend/lms/admin/tracks', [AdminController::class, 'apiCreateTrack']);
Route::put('/api/frontend/lms/admin/tracks/{id}', [AdminController::class, 'updateTrack']);
Route::delete('/api/frontend/lms/admin/tracks/{id}', [AdminController::class, 'deleteTrackApi']);
Route::get('/api/frontend/lms/admin/batches', [AdminController::class, 'listBatches']);
Route::post('/api/frontend/lms/admin/batches', [AdminController::class, 'createBatch']);
Route::post('/api/frontend/lms/admin/batches/{id}/announcements', [AdminController::class, 'createBatchAnnouncement']);

// Agent routes
Route::post('/api/frontend/lms/agents/apply', [AgentController::class, 'apply']);
Route::post('/api/frontend/lms/agents/login', [AgentController::class, 'login']);
Route::get('/api/frontend/lms/agents/courses', [AgentController::class, 'courses']);

Route::get('/api/frontend/lms/agents/me', [AgentController::class, 'me']);
Route::get('/api/frontend/lms/agents/dashboard', [AgentController::class, 'dashboard']);
Route::post('/api/frontend/lms/agents/register-student', [AgentController::class, 'registerStudent']);
Route::get('/api/frontend/lms/agents/commissions', [AgentController::class, 'commissions']);
Route::get('/api/frontend/lms/agents/withdrawals', [AgentController::class, 'withdrawalHistory']);
Route::post('/api/frontend/lms/agents/withdrawals/request', [AgentController::class, 'requestWithdrawal']);
Route::get('/api/frontend/lms/agents/notifications', [AgentController::class, 'notifications']);
Route::get('/api/frontend/lms/agents/notifications/unread', [AgentController::class, 'unreadCount']);
Route::post('/api/frontend/lms/agents/notifications/{id}/read', [AgentController::class, 'markNotificationRead']);
Route::post('/api/frontend/lms/agents/notifications/read-all', [AgentController::class, 'markAllNotificationsRead']);

Route::get('/api/frontend/lms/admin/agents/pending', [AgentController::class, 'adminPending']);
Route::get('/api/frontend/lms/admin/agents', [AgentController::class, 'adminAll']);
Route::post('/api/frontend/lms/admin/agents/{id}/approve', [AgentController::class, 'adminApprove']);
Route::post('/api/frontend/lms/admin/agents/{id}/reject', [AgentController::class, 'adminReject']);
