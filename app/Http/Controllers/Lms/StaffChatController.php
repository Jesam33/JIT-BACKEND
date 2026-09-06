<?php

namespace App\Http\Controllers\Lms;

use App\Events\MessageSent;
use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsMessage;
use App\Models\LmsNotification;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffChatController extends BaseLmsController
{
    public function groupMessages(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);

        $trackIds = \App\Models\LmsTrack::query()
            ->where('instructor_id', $teacher->id)
            ->pluck('id');

        $groupChatIds = LmsGroupChat::query()
            ->whereIn('track_id', $trackIds)
            ->pluck('id');

        $messages = LmsMessage::query()
            ->where('chat_type', 'group')
            ->whereIn('chat_id', $groupChatIds)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->with(['teacher', 'student', 'replyTo.teacher', 'replyTo.student', 'reactions'])
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'chat_id' => $m->chat_id,
                'content' => $m->content,
                'sender_role' => $m->sender_role,
                'sender_id' => $m->sender_id,
                'sender_name' => $m->sender_role === 'teacher' ? ($m->teacher?->name ?? 'Teacher') : ($m->student ? trim($m->student->first_name . ' ' . $m->student->last_name) : 'Student'),
                'attachment_url' => $m->attachment_url,
                'reply_to_id' => $m->reply_to_id,
                'reply_to' => $this->replyToPayload($m),
                'reactions' => $this->reactionsPayload($m, 'teacher', (int) $teacher->id),
                'edited_at' => $m->edited_at?->toIso8601String(),
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        return response()->json($messages);
    }

    public function mentionableUsers(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);

        $track = LmsTrack::query()->where('instructor_id', $teacher->id)->first();

        if (! $track) {
            return response()->json([]);
        }

        // Students in the track this teacher's group chat belongs to. No self-
        // filter here: this list is students only (the teacher is the viewer), and
        // the old reject() compared a student id to the teacher's session id, which
        // could wrongly drop a student whose id happened to equal the teacher's.
        $students = LmsEnrollment::query()
            ->where('track_id', $track->id)
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter()
            ->unique('id')
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => trim($s->first_name . ' ' . $s->last_name),
                'username' => $s->username,
                'role' => 'student',
            ])
            ->values();

        return response()->json($students);
    }

    public function sendGroupMessage(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);

        $track = \App\Models\LmsTrack::query()->where('instructor_id', $teacher->id)->first();

        if (! $track) {
            return response()->json(['message' => 'No assigned track.'], 400);
        }

        $groupChat = LmsGroupChat::query()->firstOrCreate(
            ['track_id' => $track->id],
            ['name' => $track->name . ' Group']
        );

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:5000'],
            'attachment_url' => ['nullable', 'string', 'max:2048'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);

        $content = $validated['content'] ?? '';

        if ($content) {
            // Notify @mentioned students. Match on the username OR the full name so
            // it works whether the sender picked from the dropdown (inserts the
            // @username) or typed the person's name, and regardless of whether the
            // student's username was ever set. Scoped to this track's roster.
            $enrolledStudents = LmsEnrollment::query()
                ->where('track_id', $track->id)
                ->with('student')
                ->get()
                ->pluck('student')
                ->filter()
                ->unique('id');

            $haystack = mb_strtolower($content);
            $notified = [];

            foreach ($enrolledStudents as $student) {
                if (isset($notified[$student->id])) {
                    continue;
                }

                $handles = [];
                if (! empty($student->username)) {
                    $handles[] = mb_strtolower($student->username);
                }
                $fullName = trim(mb_strtolower(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')));
                if ($fullName !== '') {
                    $handles[] = $fullName;
                }

                foreach ($handles as $handle) {
                    if (str_contains($haystack, '@' . $handle)) {
                        LmsNotification::query()->create([
                            'student_id' => $student->id,
                            'type' => 'mention',
                            'title' => 'You were mentioned',
                            'body' => $teacher->name . ' mentioned you: ' . $content,
                            'reference_type' => 'group_chat',
                            'reference_id' => $groupChat->id,
                        ]);
                        $notified[$student->id] = true;
                        break;
                    }
                }
            }
        }

        $message = LmsMessage::query()->create([
            'chat_type' => 'group',
            'chat_id' => $groupChat->id,
            'sender_role' => 'teacher',
            'sender_id' => $session->user_id,
            'content' => $content,
            'attachment_url' => $validated['attachment_url'] ?? null,
            'reply_to_id' => $this->resolveReplyToId($validated['reply_to_id'] ?? null, 'group', $groupChat->id),
        ]);

        $message->load(['replyTo.teacher', 'replyTo.student']);

        try {
            MessageSent::dispatch('group', $groupChat->id, [
                'id' => $message->id,
                'chat_id' => $message->chat_id,
                'content' => $message->content,
                'sender_role' => $message->sender_role,
                'sender_id' => $message->sender_id,
                'sender_name' => $teacher->name,
                'attachment_url' => $message->attachment_url,
                'reply_to_id' => $message->reply_to_id,
                'reply_to' => $this->replyToPayload($message),
                'reactions' => [],
                'created_at' => $message->created_at->toIso8601String(),
            ]);
        } catch (\Throwable) {}

        return response()->json([
            'id' => $message->id,
            'chat_id' => $message->chat_id,
            'content' => $message->content,
            'sender_role' => $message->sender_role,
            'sender_id' => $message->sender_id,
            'sender_name' => $teacher->name,
            'attachment_url' => $message->attachment_url,
            'reply_to_id' => $message->reply_to_id,
            'reply_to' => $this->replyToPayload($message),
            'reactions' => [],
            'created_at' => $message->created_at->toIso8601String(),
        ], 201);
    }

    public function deleteGroupMessage(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'teacher' || (int) $message->sender_id !== $session->user_id) {
            return response()->json(['message' => 'You can only delete your own messages.'], 403);
        }

        $message->update(['deleted_at' => now()]);

        return response()->json(['message' => 'Message deleted.', 'id' => $id]);
    }

    public function editGroupMessage(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'teacher' || (int) $message->sender_id !== $session->user_id) {
            return response()->json(['message' => 'You can only edit your own messages.'], 403);
        }

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:5000'],
            'attachment_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $message->update([
            'content' => $validated['content'] ?? '',
            'attachment_url' => $validated['attachment_url'] ?? $message->attachment_url,
            'edited_at' => now(),
        ]);

        return response()->json([
            'id' => $message->id,
            'content' => $message->content,
            'attachment_url' => $message->attachment_url,
            'edited_at' => $message->edited_at->toIso8601String(),
        ]);
    }

    public function dmMessages(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacherId = $session->user_id;

        // Roster of everyone the teacher can DM = students enrolled in any of the
        // teacher's tracks. Previously the DM tab listed only threads that already
        // had a message, so staff had no way to *start* a conversation and saw an
        // empty "No DM threads yet" panel. Ensure a thread exists for each enrolled
        // student so the whole cohort shows up and is immediately messageable.
        $trackIds = LmsTrack::query()
            ->where('instructor_id', $teacherId)
            ->pluck('id');

        $trackByStudent = [];
        foreach (LmsEnrollment::query()->whereIn('track_id', $trackIds)->get(['student_id', 'track_id']) as $enrollment) {
            if (! isset($trackByStudent[$enrollment->student_id])) {
                $trackByStudent[$enrollment->student_id] = $enrollment->track_id;
            }
        }

        $existingStudentIds = LmsDmThread::query()
            ->where('instructor_id', $teacherId)
            ->pluck('student_id')
            ->all();

        foreach (array_diff(array_keys($trackByStudent), $existingStudentIds) as $studentId) {
            LmsDmThread::query()->firstOrCreate([
                'student_id' => $studentId,
                'instructor_id' => $teacherId,
                'track_id' => $trackByStudent[$studentId],
            ]);
        }

        $threads = LmsDmThread::query()
            ->where('instructor_id', $teacherId)
            ->with(['student', 'messages' => function ($q) {
                $q->orderBy('created_at')->limit(100)
                  ->with(['replyTo.teacher', 'replyTo.student', 'reactions']);
            }])
            ->get()
            ->map(fn ($thread) => [
                'thread_id' => $thread->id,
                'student' => [
                    'id' => $thread->student?->id,
                    'name' => trim(($thread->student?->first_name ?? '') . ' ' . ($thread->student?->last_name ?? '')),
                    'email' => $thread->student?->email,
                    'profile_photo_url' => $thread->student?->profile_photo_url,
                ],
                'messages' => $thread->messages->map(fn ($m) => [
                    'id' => $m->id,
                    'content' => $m->content,
                    'sender_role' => $m->sender_role,
                    'sender_id' => $m->sender_id,
                    'from_role' => $m->sender_role,
                    'attachment_url' => $m->attachment_url,
                    'reply_to_id' => $m->reply_to_id,
                    'reply_to' => $this->replyToPayload($m),
                    'reactions' => $this->reactionsPayload($m, 'teacher', (int) $teacherId),
                    'edited_at' => $m->edited_at?->toIso8601String(),
                    'created_at' => $m->created_at->toIso8601String(),
                ]),
            ])
            // Drop threads whose student row is gone (e.g. removed from the
            // institute) so the roster never shows a blank, unusable entry.
            ->filter(fn ($t) => $t['student']['id'] !== null)
            ->values();

        return response()->json($threads);
    }

    public function sendDmMessage(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'dm_thread_id' => ['required', 'integer', 'exists:lms_dm_threads,id'],
            'content' => ['nullable', 'string', 'max:5000'],
            'attachment_url' => ['nullable', 'string', 'max:2048'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);

        $teacher = \App\Models\LmsTeacher::query()->findOrFail($session->user_id);

        $message = LmsMessage::query()->create([
            'chat_type' => 'dm',
            'chat_id' => $validated['dm_thread_id'],
            'sender_role' => 'teacher',
            'sender_id' => $session->user_id,
            'content' => $validated['content'] ?? '',
            'attachment_url' => $validated['attachment_url'] ?? null,
            'reply_to_id' => $this->resolveReplyToId($validated['reply_to_id'] ?? null, 'dm', (int) $validated['dm_thread_id']),
        ]);

        $message->load(['replyTo.teacher', 'replyTo.student']);

        try {
            MessageSent::dispatch('dm', $validated['dm_thread_id'], [
                'id' => $message->id,
                'content' => $message->content,
                'sender_role' => 'teacher',
                'sender_id' => $message->sender_id,
                'sender_name' => $teacher->name,
                'attachment_url' => $message->attachment_url,
                'reply_to_id' => $message->reply_to_id,
                'reply_to' => $this->replyToPayload($message),
                'reactions' => [],
                'created_at' => $message->created_at->toIso8601String(),
            ]);
        } catch (\Throwable) {}

        return response()->json([
            'id' => $message->id,
            'content' => $message->content,
            'sender_role' => $message->sender_role,
            'sender_id' => $message->sender_id,
            'from_role' => $message->sender_role,
            'attachment_url' => $message->attachment_url,
            'reply_to_id' => $message->reply_to_id,
            'reply_to' => $this->replyToPayload($message),
            'reactions' => [],
            'created_at' => $message->created_at->toIso8601String(),
        ], 201);
    }

    public function deleteDmMessage(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'teacher' || (int) $message->sender_id !== $session->user_id) {
            return response()->json(['message' => 'You can only delete your own messages.'], 403);
        }

        $message->update(['deleted_at' => now()]);

        return response()->json(['message' => 'Message deleted.', 'id' => $id]);
    }

    public function editDmMessage(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'teacher' || (int) $message->sender_id !== $session->user_id) {
            return response()->json(['message' => 'You can only edit your own messages.'], 403);
        }

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:5000'],
            'attachment_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $message->update([
            'content' => $validated['content'] ?? '',
            'attachment_url' => $validated['attachment_url'] ?? $message->attachment_url,
            'edited_at' => now(),
        ]);

        return response()->json([
            'id' => $message->id,
            'content' => $message->content,
            'attachment_url' => $message->attachment_url,
            'edited_at' => $message->edited_at->toIso8601String(),
        ]);
    }

    public function markGroupRead(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        LmsTeacher::query()->where('id', $session->user_id)->update(['group_chat_read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function markDmRead(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        LmsTeacher::query()->where('id', $session->user_id)->update(['dm_chat_read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacher = LmsTeacher::query()->find($session->user_id);

        if (! $teacher) {
            return response()->json(['unread_group' => 0, 'unread_dm' => 0]);
        }

        $track = LmsTrack::query()->where('instructor_id', $teacher->id)->first();

        if (! $track) {
            return response()->json(['unread_group' => 0, 'unread_dm' => 0]);
        }

        $groupChat = LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);

        $unreadGroup = LmsMessage::query()
            ->where('chat_type', 'group')
            ->where('chat_id', $groupChat->id)
            ->whereNull('deleted_at')
            ->when($teacher->group_chat_read_at, fn ($q) => $q->where('created_at', '>', $teacher->group_chat_read_at))
            ->count();

        $studentIds = LmsEnrollment::query()
            ->where('track_id', $track->id)
            ->pluck('student_id');

        $unreadDm = LmsMessage::query()
            ->where('chat_type', 'dm')
            ->where('sender_role', 'student')
            ->whereIn('sender_id', $studentIds)
            ->when($teacher->dm_chat_read_at, fn ($q) => $q->where('created_at', '>', $teacher->dm_chat_read_at))
            ->count();

        $unreadNotifs = \App\Models\LmsTeacherNotification::query()
            ->where('teacher_id', $session->user_id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'unread_notifications' => $unreadNotifs,
            'unread_group' => $unreadGroup,
            'unread_dm' => $unreadDm,
        ]);
    }

    /**
     * Add or remove the teacher's reaction (one emoji) on a message in a chat
     * they own, a group chat of one of their tracks, or a DM thread where they
     * are the instructor. Returns the message's full re-aggregated reactions.
     */
    public function toggleReaction(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in($this->allowedReactionEmojis())],
        ]);

        $teacher = LmsTeacher::query()->findOrFail($session->user_id);

        $message = LmsMessage::query()->whereNull('deleted_at')->findOrFail($id);

        if ($message->chat_type === 'group') {
            $trackIds = LmsTrack::query()->where('instructor_id', $teacher->id)->pluck('id');
            $reachable = LmsGroupChat::query()
                ->whereIn('track_id', $trackIds)
                ->where('id', $message->chat_id)
                ->exists();
        } elseif ($message->chat_type === 'dm') {
            $reachable = LmsDmThread::query()
                ->where('id', $message->chat_id)
                ->where('instructor_id', $teacher->id)
                ->exists();
        } else {
            $reachable = false;
        }

        if (! $reachable) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }

        $reactions = $this->toggleMessageReaction($message, 'teacher', (int) $teacher->id, $validated['emoji']);

        return response()->json([
            'message_id' => $message->id,
            'reactions' => $reactions,
        ]);
    }
}
