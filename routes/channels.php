<?php

use App\Models\LmsGroupChat;
use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsTrack;
use App\Models\LmsTeacher;
use App\Models\LmsStudent;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.group.{chatId}', function ($user, $chatId) {
    $groupChat = LmsGroupChat::query()->find($chatId);
    if (! $groupChat) return false;

    if ($user instanceof LmsTeacher) {
        $track = LmsTrack::query()->find($groupChat->track_id);
        return $track && (int) $track->instructor_id === $user->id
            ? ['id' => $user->id, 'name' => $user->name, 'role' => 'teacher']
            : false;
    }

    if ($user instanceof LmsStudent) {
        $enrolled = LmsEnrollment::query()
            ->where('student_id', $user->id)
            ->where('track_id', $groupChat->track_id)
            ->exists();
        return $enrolled
            ? ['id' => $user->id, 'name' => trim($user->first_name . ' ' . $user->last_name), 'role' => 'student']
            : false;
    }

    return false;
});

Broadcast::channel('chat.dm.{threadId}', function ($user, $threadId) {
    $thread = LmsDmThread::query()->find($threadId);
    if (! $thread) return false;

    if ($user instanceof LmsTeacher) {
        return (int) $thread->teacher_id === $user->id
            ? ['id' => $user->id, 'name' => $user->name, 'role' => 'teacher']
            : false;
    }

    if ($user instanceof LmsStudent) {
        return (int) $thread->student_id === $user->id
            ? ['id' => $user->id, 'name' => trim($user->first_name . ' ' . $user->last_name), 'role' => 'student']
            : false;
    }

    return false;
});
