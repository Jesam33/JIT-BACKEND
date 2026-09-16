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
    /**
     * The cohort whose group chat the actor is reading or writing.
     *
     * A group chat belongs to ONE cohort (lms_group_chats.track_id), so unlike the
     * other staff surfaces this one cannot simply widen for an academy owner. It
     * resolves to the cohort named by an optional `track_id` (bounded to the
     * actor's own — see actorTrackIds), else the actor's first cohort, which is
     * exactly what a single-cohort staffer already got. One resolver for both the
     * @mention roster and the chat a message lands in, so the two cannot disagree.
     */
    private function groupChatTrack(Request $request, LmsTeacher $actor): ?LmsTrack
    {
        $trackIds = array_map('intval', $this->actorTrackIds($actor));

        if (! $trackIds) {
            return null;
        }

        $requested = $request->input('track_id');

        if ($requested !== null && in_array((int) $requested, $trackIds, true)) {
            return LmsTrack::query()->find((int) $requested);
        }

        return LmsTrack::query()->whereIn('id', $trackIds)->orderBy('id')->first();
    }

    // Upload a file the staffer picked in the chat composer (group or DM).
    // Returns {url, path}; the composer sends the url as attachment_url with
    // the next message. Staff twin of StudentChatController::uploadAttachment.
    public function uploadAttachment(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $this->storeChatAttachment($request);
    }

    public function groupMessages(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // ONE cohort, resolved by the same helper the send path uses, so what the
        // actor reads is exactly what they write to. Reading every cohort at once
        // (the earlier behaviour) interleaved several cohorts' conversations into
        // one stream with no cohort label, while a reply still landed in only one
        // of them — an academy owner with many cohorts could not tell who they
        // were talking to. The caller picks the cohort with `track_id`; with no
        // pick (a single-cohort staffer, the common case) it is their first, which
        // is what they always got.
        $track = $this->groupChatTrack($request, $actor);

        if (! $track) {
            return response()->json([]);
        }

        $groupChatIds = LmsGroupChat::query()
            ->where('track_id', $track->id)
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
                'reactions' => $this->reactionsPayload($m, 'teacher', (int) $actor->id),
                'edited_at' => $m->edited_at?->toIso8601String(),
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        return response()->json($messages);
    }

    public function mentionableUsers(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $track = $this->groupChatTrack($request, $actor);

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

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $track = $this->groupChatTrack($request, $actor);

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
                            'body' => $actor->name . ' mentioned you: ' . $content,
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
            'sender_id' => $actor->id,
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
                'sender_name' => $actor->name,
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
            'sender_name' => $actor->name,
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

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'teacher' || (int) $message->sender_id !== $actor->id) {
            return response()->json(['message' => 'You can only delete your own messages.'], 403);
        }

        $message->update(['deleted_at' => now()]);

        return response()->json(['message' => 'Message deleted.', 'id' => $id]);
    }

    public function editGroupMessage(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'teacher' || (int) $message->sender_id !== $actor->id) {
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

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $teacherId = $actor->id;

        // Roster of everyone the teacher can DM = students enrolled in any of the
        // teacher's tracks (every cohort in the academy for the owner's mirror).
        // Previously the DM tab listed only threads that already had a message, so
        // staff had no way to *start* a conversation and saw an empty "No DM
        // threads yet" panel. Ensure a thread exists for each enrolled student so
        // the whole roster shows up and is immediately messageable.
        $trackIds = $this->actorTrackIds($actor);

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

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'dm_thread_id' => ['required', 'integer'],
            'content' => ['nullable', 'string', 'max:5000'],
            'attachment_url' => ['nullable', 'string', 'max:2048'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);

        // The thread must be one THIS actor is the instructor of. A bare
        // `exists:lms_dm_threads,id` is a raw query-builder rule (global scopes do
        // not apply) and the lookup below was unscoped, so any signed-in staffer
        // could post into — and thereby notify the student of — another teacher's
        // conversation.
        $dmThread = LmsDmThread::query()
            ->where('instructor_id', $actor->id)
            ->findOrFail($validated['dm_thread_id']);

        $message = LmsMessage::query()->create([
            'chat_type' => 'dm',
            'chat_id' => $validated['dm_thread_id'],
            'sender_role' => 'teacher',
            'sender_id' => $actor->id,
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
                'sender_name' => $actor->name,
                'attachment_url' => $message->attachment_url,
                'reply_to_id' => $message->reply_to_id,
                'reply_to' => $this->replyToPayload($message),
                'reactions' => [],
                'created_at' => $message->created_at->toIso8601String(),
            ]);
        } catch (\Throwable) {}

        // A direct message notifies its recipient. Here that is the thread's
        // student; deduped on the unread state so one burst of replies makes at most
        // one notification (and one email) until the student opens the thread.
        try {
            if ($dmThread && (int) $dmThread->student_id > 0) {
                $hasPending = LmsNotification::query()
                    ->where('student_id', (int) $dmThread->student_id)
                    ->where('type', 'message')
                    ->where('reference_type', 'dm_thread')
                    ->where('reference_id', $dmThread->id)
                    ->where('is_read', false)
                    ->exists();
                if (! $hasPending) {
                    $preview = trim($validated['content'] ?? '');
                    if ($preview === '') {
                        $preview = 'Sent an attachment';
                    } elseif (mb_strlen($preview) > 140) {
                        $preview = mb_substr($preview, 0, 140) . '...';
                    }
                    LmsNotification::query()->create([
                        'student_id' => (int) $dmThread->student_id,
                        'type' => 'message',
                        'title' => 'New message from ' . $actor->name,
                        'body' => $preview,
                        'reference_type' => 'dm_thread',
                        'reference_id' => $dmThread->id,
                    ]);
                }
            }
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

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'teacher' || (int) $message->sender_id !== $actor->id) {
            return response()->json(['message' => 'You can only delete your own messages.'], 403);
        }

        $message->update(['deleted_at' => now()]);

        return response()->json(['message' => 'Message deleted.', 'id' => $id]);
    }

    public function editDmMessage(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $message = LmsMessage::query()->findOrFail($id);

        if ($message->sender_role !== 'teacher' || (int) $message->sender_id !== $actor->id) {
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

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        LmsTeacher::query()->where('id', $actor->id)->update(['group_chat_read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function markDmRead(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        LmsTeacher::query()->where('id', $actor->id)->update(['dm_chat_read_at' => now()]);

        // Opening the DM tab clears its "new message" notifications too, so the
        // navbar bell (which counts unread notifications) does not keep flagging
        // direct messages the teacher has already read.
        \App\Models\LmsTeacherNotification::query()
            ->where('teacher_id', $actor->id)
            ->where('type', 'message')
            ->where('reference_type', 'dm_thread')
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['ok' => true]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Actor-aware: a staffer's badge counts their own cohorts, the owner's
        // mirror counts every cohort in the academy.
        $trackIds = $this->actorTrackIds($actor);

        if (! $trackIds) {
            return response()->json(['unread_group' => 0, 'unread_dm' => 0]);
        }

        $groupChatIds = LmsGroupChat::query()
            ->whereIn('track_id', $trackIds)
            ->pluck('id');

        $unreadGroup = LmsMessage::query()
            ->where('chat_type', 'group')
            ->whereIn('chat_id', $groupChatIds)
            ->whereNull('deleted_at')
            ->when($actor->group_chat_read_at, fn ($q) => $q->where('created_at', '>', $actor->group_chat_read_at))
            ->count();

        // Scoped to the actor's OWN threads: it used to count any message from a
        // student of theirs, which included messages those students sent to a
        // different instructor.
        $dmThreadIds = LmsDmThread::query()
            ->where('instructor_id', $actor->id)
            ->pluck('id');

        $unreadDm = LmsMessage::query()
            ->where('chat_type', 'dm')
            ->where('sender_role', 'student')
            ->whereIn('chat_id', $dmThreadIds)
            ->whereNull('deleted_at')
            ->when($actor->dm_chat_read_at, fn ($q) => $q->where('created_at', '>', $actor->dm_chat_read_at))
            ->count();

        $unreadNotifs = \App\Models\LmsTeacherNotification::query()
            ->where('teacher_id', $actor->id)
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

        // A staff session's own row, or the academy owner's academy-wide mirror
        // (full parity: the owner portal mounts the staff chat too).
        $actor = $this->staffActor($request);

        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in($this->allowedReactionEmojis())],
        ]);

        $message = LmsMessage::query()->whereNull('deleted_at')->findOrFail($id);

        if ($message->chat_type === 'group') {
            // Actor-aware: the cohorts this actor may react in, the owner's mirror
            // covering the whole academy.
            $reachable = LmsGroupChat::query()
                ->whereIn('track_id', $this->actorTrackIds($actor))
                ->where('id', $message->chat_id)
                ->exists();
        } elseif ($message->chat_type === 'dm') {
            $reachable = LmsDmThread::query()
                ->where('id', $message->chat_id)
                ->where('instructor_id', $actor->id)
                ->exists();
        } else {
            $reachable = false;
        }

        if (! $reachable) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }

        $reactions = $this->toggleMessageReaction($message, 'teacher', (int) $actor->id, $validated['emoji']);

        return response()->json([
            'message_id' => $message->id,
            'reactions' => $reactions,
        ]);
    }
}
