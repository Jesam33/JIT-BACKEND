<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsTeacherNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffNotificationController extends BaseLmsController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $notifications = LmsTeacherNotification::query()
            ->where('teacher_id', $session->user_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'is_read' => (bool) $n->is_read,
                'reference_type' => $n->reference_type,
                'reference_id' => $n->reference_id,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $count = LmsTeacherNotification::query()
            ->where('teacher_id', $session->user_id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'unread_notifications' => $count,
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $notification = LmsTeacherNotification::query()
            ->where('id', $id)
            ->where('teacher_id', $session->user_id)
            ->firstOrFail();

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->ensureLmsEnabled();

        $session = $this->sessionFromRequest($request, 'staff');

        if (! $session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        LmsTeacherNotification::query()
            ->where('teacher_id', $session->user_id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
