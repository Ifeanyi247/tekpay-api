<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Notifications retrieved successfully',
            'data' => $notifications
        ]);
    }

    public function markAsRead(Request $request)
    {
        $validated = $request->validate([
            'notification_ids' => 'required|array',
            'notification_ids.*' => 'exists:notifications,id'
        ]);

        Notification::whereIn('id', $validated['notification_ids'])
            ->where('user_id', auth()->id())
            ->update(['is_read' => true]);

        return response()->json([
            'status' => true,
            'message' => 'Notifications marked as read'
        ]);
    }

    public function markAllAsRead()
    {
        Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'status' => true,
            'message' => 'All notifications marked as read'
        ]);
    }

    public function unreadCount()
    {
        $count = Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->count();

        return response()->json([
            'status' => true,
            'message' => 'Unread count retrieved successfully',
            'data' => [
                'count' => $count
            ]
        ]);
    }
}
