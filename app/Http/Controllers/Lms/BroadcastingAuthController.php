<?php

namespace App\Http\Controllers\Lms;

use App\Models\LmsDmThread;
use App\Models\LmsEnrollment;
use App\Models\LmsGroupChat;
use App\Models\LmsSession;
use App\Models\LmsStudent;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pusher\Pusher;

class BroadcastingAuthController extends Controller
{
    public function auth(Request $request): JsonResponse
    {
        $channel = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        if (! $channel || ! $socketId) {
            return response()->json(['message' => 'Missing channel_name or socket_id'], 400);
        }

        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $session = LmsSession::query()
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        if ($session->role === 'staff') {
            $user = LmsTeacher::query()->find($session->user_id);
        } else {
            $user = LmsStudent::query()->find($session->user_id);
        }

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $authorized = $this->authorizeChannel($channel, $user);
        if (! $authorized) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $pusher = new Pusher(
            config('broadcasting.connections.reverb.key'),
            config('broadcasting.connections.reverb.secret'),
            config('broadcasting.connections.reverb.app_id'),
            [
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
                'useTLS' => config('broadcasting.connections.reverb.options.useTLS'),
            ]
        );

        if (str_starts_with($channel, 'presence-')) {
            $role = $session->role === 'staff' ? 'teacher' : 'student';
            $name = $role === 'teacher'
                ? $user->name
                : trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

            $channelData = [
                'user_id' => (string) $user->id,
                'user_info' => ['name' => $name, 'role' => $role],
            ];

            $auth = $pusher->presence_auth($channel, $socketId, (string) $user->id, $channelData);
        } else {
            $auth = $pusher->socket_auth($channel, $socketId);
        }

        return response()->json(json_decode($auth, true));
    }

    private function authorizeChannel(string $channel, LmsTeacher|LmsStudent $user): bool
    {
        if (preg_match('/^presence-chat\.group\.(\d+)$/', $channel, $m)) {
            $chatId = (int) $m[1];
            $groupChat = LmsGroupChat::query()->find($chatId);
            if (! $groupChat) return false;

            if ($user instanceof LmsTeacher) {
                $track = LmsTrack::query()->find($groupChat->track_id);
                return $track && (int) $track->instructor_id === $user->id;
            }

            return LmsEnrollment::query()
                ->where('student_id', $user->id)
                ->where('track_id', $groupChat->track_id)
                ->exists();
        }

        if (preg_match('/^presence-chat\.dm\.(\d+)$/', $channel, $m)) {
            $threadId = (int) $m[1];
            $thread = LmsDmThread::query()->find($threadId);
            if (! $thread) return false;

            if ($user instanceof LmsTeacher) {
                return (int) ($thread->instructor_id ?? $thread->teacher_id) === $user->id;
            }

            return (int) $thread->student_id === $user->id;
        }

        return false;
    }
}
