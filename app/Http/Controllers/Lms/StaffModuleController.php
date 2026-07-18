<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsCourse;
use App\Models\LmsModule;
use App\Models\LmsModuleContent;
use App\Models\LmsScheduledClass;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StaffModuleController extends BaseLmsController
{
    private function teacher(Request $request): ?LmsTeacher
    {
        $session = $this->sessionFromRequest($request, 'staff');
        if (! $session) return null;
        return LmsTeacher::find($session->user_id);
    }

    private function teacherOrFail(Request $request): LmsTeacher
    {
        $t = $this->teacher($request);
        if (! $t) abort(401, 'Unauthorized');
        return $t;
    }

    private function assignedCourseIds(LmsTeacher $teacher): array
    {
        return LmsTrack::where('instructor_id', $teacher->id)->pluck('course_id')->toArray();
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $query = LmsModule::with(['contents', 'course'])
            ->whereHas('course', fn($q) => $q->whereIn('id', $courseIds))
            ->orderBy('sort_order');

        if ($request->has('course_id')) {
            $cid = $request->integer('course_id');
            if (!in_array($cid, $courseIds)) {
                return response()->json(['message' => 'Course not in your assignment'], 403);
            }
            $query->where('course_id', $cid);
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($s = $request->input('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%");
            });
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $validated = $request->validate([
            'course_id' => 'required|exists:lms_courses,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'objectives' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|in:draft,published',
        ]);

        if (!in_array((int) $validated['course_id'], $courseIds)) {
            return response()->json(['message' => 'Course not in your assignment'], 403);
        }

        $module = LmsModule::create($validated);

        return response()->json($module->load('contents'), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $module = LmsModule::with(['contents', 'course'])->findOrFail($id);

        if (!in_array($module->course_id, $courseIds)) {
            return response()->json(['message' => 'Module not in your assignment'], 403);
        }

        return response()->json($module);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $module = LmsModule::findOrFail($id);

        if (!in_array($module->course_id, $courseIds)) {
            return response()->json(['message' => 'Module not in your assignment'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'objectives' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|in:draft,published',
        ]);

        $module->update($validated);

        return response()->json($module->load('contents'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $module = LmsModule::findOrFail($id);

        if (!in_array($module->course_id, $courseIds)) {
            return response()->json(['message' => 'Module not in your assignment'], 403);
        }

        $module->delete();
        return response()->json(['message' => 'Module deleted.']);
    }

    public function addContent(Request $request, int $moduleId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $module = LmsModule::findOrFail($moduleId);
        if (!in_array($module->course_id, $courseIds)) {
            return response()->json(['message' => 'Module not in your assignment'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:slides,pdf,video,link,text,code,file',
            'content_url' => 'nullable|string',
            'content_body' => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ]);

        $validated['module_id'] = $moduleId;
        $content = LmsModuleContent::create($validated);

        return response()->json($content, 201);
    }

    public function updateContent(Request $request, int $moduleId, int $contentId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $module = LmsModule::findOrFail($moduleId);
        if (!in_array($module->course_id, $courseIds)) {
            return response()->json(['message' => 'Module not in your assignment'], 403);
        }

        $content = LmsModuleContent::where('module_id', $moduleId)->findOrFail($contentId);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:slides,pdf,video,link,text,code,file',
            'content_url' => 'nullable|string',
            'content_body' => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ]);

        $content->update($validated);

        return response()->json($content);
    }

    public function removeContent(Request $request, int $moduleId, int $contentId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $module = LmsModule::findOrFail($moduleId);
        if (!in_array($module->course_id, $courseIds)) {
            return response()->json(['message' => 'Module not in your assignment'], 403);
        }

        $content = LmsModuleContent::where('module_id', $moduleId)->findOrFail($contentId);

        if ($content->file_path) {
            Storage::disk('public')->delete($content->file_path);
        }

        $content->delete();
        return response()->json(['message' => 'Content removed.']);
    }

    public function uploadContentFile(Request $request, int $moduleId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $module = LmsModule::findOrFail($moduleId);
        if (!in_array($module->course_id, $courseIds)) {
            return response()->json(['message' => 'Module not in your assignment'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:102400',
        ]);

        $path = $request->file('file')->store('module-contents', 'public');

        return response()->json([
            'url' => Storage::url($path),
            'path' => $path,
        ]);
    }

    public function reorderContents(Request $request, int $moduleId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $module = LmsModule::findOrFail($moduleId);
        if (!in_array($module->course_id, $courseIds)) {
            return response()->json(['message' => 'Module not in your assignment'], 403);
        }

        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:lms_module_contents,id',
            'items.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($request->input('items') as $item) {
            LmsModuleContent::where('module_id', $moduleId)
                ->where('id', $item['id'])
                ->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['message' => 'Reordered.']);
    }

    public function scheduleClass(Request $request, int $moduleId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);
        $courseIds = $this->assignedCourseIds($teacher);

        $module = LmsModule::findOrFail($moduleId);
        if (!in_array($module->course_id, $courseIds)) {
            return response()->json(['message' => 'Module not in your assignment'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'meeting_url' => 'nullable|string',
            'meeting_id' => 'nullable|string',
            'meeting_password' => 'nullable|string',
            'location' => 'nullable|string',
        ]);

        $validated['module_id'] = $moduleId;
        $validated['teacher_id'] = $teacher->id;

        $class = LmsScheduledClass::create($validated);

        $students = \App\Models\LmsEnrollment::query()
            ->whereHas('track', fn($q) => $q->whereIn('course_id', $courseIds))
            ->pluck('student_id');

        foreach ($students as $studentId) {
            $meetingPwd = $validated['meeting_password'] ?? null;
            \App\Models\LmsNotification::create([
                'student_id' => $studentId,
                'type' => 'class_scheduled',
                'title' => 'New class scheduled: ' . $validated['title'],
                'body' => 'A new class "' . $validated['title'] . '" has been scheduled for ' . $validated['starts_at'] . '.' . ($meetingPwd ? ' Meeting password: ' . $meetingPwd : ''),
                'reference_type' => 'scheduled_class',
                'reference_id' => $class->id,
            ]);
        }

        return response()->json($class->load('module'), 201);
    }

    public function classes(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);

        $query = LmsScheduledClass::with(['module.course'])
            ->where('teacher_id', $teacher->id)
            ->orderBy('starts_at');

        if ($request->has('module_id')) {
            $query->where('module_id', $request->integer('module_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->get());
    }

    public function updateClass(Request $request, int $classId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);

        $class = LmsScheduledClass::where('teacher_id', $teacher->id)->findOrFail($classId);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'required|date|after:starts_at',
            'meeting_url' => 'nullable|string',
            'meeting_id' => 'nullable|string',
            'meeting_password' => 'nullable|string',
            'location' => 'nullable|string',
            'status' => 'sometimes|in:scheduled,ongoing,completed,cancelled',
        ]);

        $class->update($validated);

        return response()->json($class->load('module.course'));
    }

    public function destroyClass(Request $request, int $classId): JsonResponse
    {
        $this->ensureLmsEnabled();
        $teacher = $this->teacherOrFail($request);

        LmsScheduledClass::where('teacher_id', $teacher->id)->findOrFail($classId)->delete();
        return response()->json(['message' => 'Class cancelled.']);
    }
}
