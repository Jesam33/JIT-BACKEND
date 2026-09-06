<?php

namespace App\Http\Controllers\Lms;

use App\Events\MessageSent;
use App\Models\LmsChatReadState;
use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsMessage;
use App\Models\LmsStudent;
use App\Models\LmsTrack;
use App\Models\LmsNotification;
use App\Models\LmsTeacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentChatController extends BaseLmsController
{
    private function markGroupAsRead(int $studentId, int $chatId): void
    {
        LmsChatReadState::query()->updateOrCreate(
            ['student_id' => $studentId, 'chat_type' => 'group', 'chat_id' => $chatId],
            ['last_read_at' => now()]
        );
    }

    private function markDmAsRead(int $studentId, int $chatId): void
    {
        LmsChatReadState::query()->updateOrCreate(
            ['student_id' => $studentId, 'chat_type' => 'dm', 'chat_id' => $chatId],
            ['last_read_at' => now()]
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $enrollment = LmsEnrollment::query()->where('student_id', $session->user_id)->first();

        if (! $enrollment) {
            return response()->json([
                'unread_notifications' => 0,
                'unread_group' => 0,
                'unread_dm' => 0,
            ]);
        }

        $track = LmsTrack::query()->find($enrollment->track_id);

        if (! $track) {
            return response()->json([
                'unread_notifications' => 0,
                'unread_group' => 0,
                'unread_dm' => 0,
            ]);
        }

        $groupChat = LmsGroupChat::query()->firstOrCreate(['track_id' => $track->id]);
        $dmThread = LmsDmThread::query()->firstOrCreate([
            'student_id' => $session->user_id,
            'instructor_id' => $track->instructor_id,
            'track_id' => $track->id,
        ]);

        $unreadNotifs = LmsNotification::query()
            ->where('student_id', $session->user_id)
            ->where('is_read', false)
            ->count();

        $groupReadState = LmsChatReadState::query()
            ->where('student_id', $session->user_id)
            ->where('chat_type', 'group')
            ->where('chat_id', $groupChat->id)
            ->first();

        $unreadGroup = LmsMessage::query()
            ->where('chat_type', 'group')
            ->where('chat_id', $groupChat->id)
            ->whereNull('deleted_at')
            ->when($groupReadState?->last_read_at, fn ($q) => $q->where('created_at', '>', $groupReadState->last_read_at))
            ->count();

        $dmReadState = LmsChatReadState::query()
            ->where('student_id', $session->user_id)
            ->where('chat_type', 'dm')
            ->where('chat_id', $dmThread->id)
            ->first();

        $unreadDm = LmsMessage::query()
            ->where('chat_type', 'dm')
            ->where('chat_id', $dmThread->id)
            ->when($dmReadState?->last_read_at, fn ($q) => $q->where('created_at', '>', $dmReadState->last_read_at))
            ->count();

        return response()->json([
            'unread_notifications' => $unreadNotifs,
            'unread_group' => $unreadGroup,
            'unread_dm' => $unreadDm,
        ]);
    }

    public function markGroupRead(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $session = $this->sessionFromRequest($request, 'student');
        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        [$track, $groupChat] = $this->ensureStudentTrackContext($session->user_id);
        $this->markGroupAsRead($session->user_id, $groupChat->id);
        return response()->json(['ok' => true]);
    }

    public function markDmRead(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();
        $session = $this->sessionFromRequest($request, 'student');
        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        [$track, $groupChat, $dmThread] = $this->ensureStudentTrackContext($session->user_id);
        $this->markDmAsRead($session->user_id, $dmThread->id);
        return response()->json(['ok' => true]);
    }

    public function messages(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $messages = LmsMessage::query()
            ->where('sender_role', 'student')
            ->where('sender_id', $session->user_id)
            ->orWhere(function ($q) use ($session) {
                $q->where('receiver_role', 'student')
                  ->where('receiver_id', $session->user_id);
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json($messages);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'receiver_id' => ['required', 'integer', 'exists:lms_teachers,id'],
            'content' => ['nullable', 'string', 'max:5000'],
            'attachment_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $message = LmsMessage::query()->create([
            'sender_role' => 'student',
            'sender_id' => $session->user_id,
            'receiver_role' => 'teacher',
            'receiver_id' => $validated['receiver_id'],
            'content' => $validated['content'] ?? '',
            'attachment_url' => $validated['attachment_url'] ?? null,
        ]);

        return response()->json($message, 201);
    }

    public function chatBootstrap(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        [$track, $groupChat, $dmThread] = $this->ensureStudentTrackContext($session->user_id);

        return response()->json([
            'track' => [
                'id' => $track->id,
                'name' => $track->name,
                'course_title' => $track->course?->title,
            ],
            'group_chat' => [
                'id' => $groupChat->id,
                'track_id' => $groupChat->track_id,
            ],
            'dm_thread' => [
                'id' => $dmThread->id,
                'instructor_id' => $dmThread->instructor_id,
                'instructor_name' => optional($dmThread->instructor)->name,
            ],
        ]);
    }

    public function groupMessages(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        [$track, $groupChat] = $this->ensureStudentTrackContext($session->user_id);

        $this->markGroupAsRead($session->user_id, $groupChat->id);

        $messages = LmsMessage::query()
            ->where('chat_type', 'group')
            ->where('chat_id', $groupChat->id)
            ->whereNull('deleted_at')
            ->with(['teacher', 'student', 'replyTo.teacher', 'replyTo.student', 'reactions'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->reverse()
            ->values()
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
                'reactions' => $this->reactionsPayload($m, 'student', $session->user_id),
                'edited_at' => $m->edited_at?->toIso8601String(),
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        return response()->json($messages);
    }

    public function mentionableUsers(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        [$track, $groupChat] = $this->ensureStudentTrackContext($session->user_id);

        $students = LmsEnrollment::query()
            ->where('track_id', $track->id)
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter()
            ->reject(fn ($s) => (int) $s->id === $session->user_id)
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => trim($s->first_name . ' ' . $s->last_name),
                'username' => $s->username,
                'role' => 'student',
            ]);

        $instructor = LmsTeacher::query()->find($track->instructor_id);

        $users = collect(
            $instructor ? [['id' => $instructor->id, 'name' => $instructor->name, 'username' => $instructor->username, 'role' => 'teacher']] : []
        )->concat($students)->values();

        return response()->json($users);
    }

    public function sendGroupMessage(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        [$track, $groupChat] = $this->ensureStudentTrackContext($session->user_id);

        $this->markGroupAsRead($session->user_id, $groupChat->id);

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:5000'],
            'attachment_url' => ['nullable', 'string', 'max:2048'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);

        $content = $validated['content'] ?? '';

        if ($content) {
            preg_match_all('/@(\w+)/u', $content, $matches);
            foreach ($matches[1] as $mentionedUsername) {
                if (strtolower($mentionedUsername) === 'tutor') {
                    continue;
                }
                $student = \App\Models\LmsStudent::query()
                    ->where('username', $mentionedUsername)
                    ->first();
                if ($student) {
                    LmsNotification::query()->create([
                        'student_id' => $student->id,
                        'type' => 'mention',
                        'title' => 'You were mentioned',
                        'body' => 'Someone mentioned you in the group chat',
                        'reference_type' => 'group_chat',
                        'reference_id' => $groupChat->id,
                    ]);
                }
            }
        }

        $student = \App\Models\LmsStudent::query()->findOrFail($session->user_id);

        $replyToId = $this->resolveReplyToId($validated['reply_to_id'] ?? null, 'group', $groupChat->id);

        $message = LmsMessage::query()->create([
            'chat_type' => 'group',
            'chat_id' => $groupChat->id,
            'sender_role' => 'student',
            'sender_id' => $session->user_id,
            'content' => $content,
            'attachment_url' => $validated['attachment_url'] ?? null,
            'reply_to_id' => $replyToId,
        ]);

        $message->load(['replyTo.teacher', 'replyTo.student']);

        $studentName = trim($student->first_name . ' ' . $student->last_name);

        try {
            MessageSent::dispatch('group', $groupChat->id, [
                'id' => $message->id,
                'chat_id' => $message->chat_id,
                'content' => $message->content,
                'sender_role' => $message->sender_role,
                'sender_id' => $message->sender_id,
                'sender_name' => $studentName,
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
            'sender_name' => $studentName,
            'attachment_url' => $message->attachment_url,
            'reply_to_id' => $message->reply_to_id,
            'reply_to' => $this->replyToPayload($message),
            'reactions' => [],
            'created_at' => $message->created_at->toIso8601String(),
        ], 201);
    }

    public function dmMessages(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        [$track, $groupChat, $dmThread] = $this->ensureStudentTrackContext($session->user_id);

        $this->markDmAsRead($session->user_id, $dmThread->id);

        $messages = LmsMessage::query()
            ->where('chat_type', 'dm')
            ->where('chat_id', $dmThread->id)
            ->with(['replyTo.teacher', 'replyTo.student', 'reactions'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'id' => $m->id,
                'content' => $m->content,
                'sender_role' => $m->sender_role,
                'sender_id' => $m->sender_id,
                'from_role' => $m->sender_role,
                'attachment_url' => $m->attachment_url,
                'reply_to_id' => $m->reply_to_id,
                'reply_to' => $this->replyToPayload($m),
                'reactions' => $this->reactionsPayload($m, 'student', $session->user_id),
                'edited_at' => $m->edited_at?->toIso8601String(),
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        return response()->json($messages);
    }

    public function sendDmMessage(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        [$track, $groupChat, $dmThread] = $this->ensureStudentTrackContext($session->user_id);

        $this->markDmAsRead($session->user_id, $dmThread->id);

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:5000'],
            'attachment_url' => ['nullable', 'string', 'max:2048'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);

        $student = \App\Models\LmsStudent::query()->findOrFail($session->user_id);

        $replyToId = $this->resolveReplyToId($validated['reply_to_id'] ?? null, 'dm', $dmThread->id);

        $message = LmsMessage::query()->create([
            'chat_type' => 'dm',
            'chat_id' => $dmThread->id,
            'sender_role' => 'student',
            'sender_id' => $session->user_id,
            'content' => $validated['content'] ?? '',
            'attachment_url' => $validated['attachment_url'] ?? null,
            'reply_to_id' => $replyToId,
        ]);

        $message->load(['replyTo.teacher', 'replyTo.student']);

        $studentName = trim($student->first_name . ' ' . $student->last_name);

        try {
            MessageSent::dispatch('dm', $dmThread->id, [
                'id' => $message->id,
                'content' => $message->content,
                'sender_role' => $message->sender_role,
                'sender_id' => $message->sender_id,
                'sender_name' => $studentName,
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
            'sender_name' => $studentName,
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

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'student' || (int) $message->sender_id !== $session->user_id) {
            return response()->json(['message' => 'You can only delete your own messages.'], 403);
        }

        $message->update(['deleted_at' => now()]);

        return response()->json(['message' => 'Message deleted.', 'id' => $id]);
    }

    public function editGroupMessage(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'student' || (int) $message->sender_id !== $session->user_id) {
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
            'sender_role' => $message->sender_role,
            'sender_id' => $message->sender_id,
            'sender_name' => trim($message->student->first_name . ' ' . $message->student->last_name),
            'attachment_url' => $message->attachment_url,
            'edited_at' => $message->edited_at->toIso8601String(),
            'created_at' => $message->created_at->toIso8601String(),
        ]);
    }

    public function deleteDmMessage(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'student' || (int) $message->sender_id !== $session->user_id) {
            return response()->json(['message' => 'You can only delete your own messages.'], 403);
        }

        $message->update(['deleted_at' => now()]);

        return response()->json(['message' => 'Message deleted.', 'id' => $id]);
    }

    public function editDmMessage(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'student' || (int) $message->sender_id !== $session->user_id) {
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
            'sender_role' => $message->sender_role,
            'sender_id' => $message->sender_id,
            'from_role' => $message->sender_role,
            'attachment_url' => $message->attachment_url,
            'edited_at' => $message->edited_at->toIso8601String(),
            'created_at' => $message->created_at->toIso8601String(),
        ]);
    }

    /**
     * Add or remove the student's reaction (one emoji) on a message they can
     * see, a message in their track's group chat or their instructor DM.
     * Returns the message's full re-aggregated reaction list.
     */
    public function toggleReaction(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'student');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in($this->allowedReactionEmojis())],
        ]);

        [$track, $groupChat, $dmThread] = $this->ensureStudentTrackContext($session->user_id);

        $message = LmsMessage::query()->whereNull('deleted_at')->findOrFail($id);

        $reachable = ($message->chat_type === 'group' && (int) $message->chat_id === (int) $groupChat->id)
            || ($message->chat_type === 'dm' && (int) $message->chat_id === (int) $dmThread->id);

        if (! $reachable) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }

        $reactions = $this->toggleMessageReaction($message, 'student', (int) $session->user_id, $validated['emoji']);

        return response()->json([
            'message_id' => $message->id,
            'reactions' => $reactions,
        ]);
    }
}
