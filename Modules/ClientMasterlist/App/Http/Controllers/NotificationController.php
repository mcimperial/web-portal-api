<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Notification;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Attachment;

use Illuminate\Support\Facades\Schema;
use \App\Http\Traits\UppercaseInput;
use \App\Http\Traits\PasswordDeleteValidation;

class NotificationController extends Controller
{
    use UppercaseInput, PasswordDeleteValidation;

    // List enrollees for notification
    public function enrollees()
    {
        $enrollees = Enrollee::where('status', 'ACTIVE')->get();
        return response()->json($enrollees);
    }

    // List notifications for an enrollment
    public function index($enrollmentId)
    {
        $notifications = Notification::where('enrollment_id', $enrollmentId)->orderByDesc('created_at')->get();
        return response()->json($notifications);
    }

    public function single($notificationId)
    {
        $notification = Notification::findOrFail($notificationId);
        return response()->json($notification);
    }

    // Store/send notification
    public function store(Request $request)
    {
        $data = $request->validate([
            'enrollment_id' => 'required',
            'notification_type' => 'nullable|string',
            'to' => 'nullable|string',
            'cc' => 'nullable|string',
            'bcc' => 'nullable|string',
            'title' => 'required|string',
            'subject' => 'required|string',
            'message' => 'required|string',
            'is_html' => 'boolean',
            'schedule' => 'nullable|string',
        ]);
        // Uppercase all fields except 'message'
        $upperData = $data;
        foreach ($upperData as $key => $value) {
            if ($key !== 'message' && is_string($value)) {
                $upperData[$key] = $this->uppercaseStrings([$key => $value])[$key];
            }
        }
        $notification = Notification::create($upperData);
        return response()->json($notification, 201);
    }

    // Update notification
    public function update(Request $request, $id)
    {
        $notification = Notification::findOrFail($id);
        $data = $request->validate([
            'notification_type' => 'nullable|string',
            'to' => 'nullable|string',
            'cc' => 'nullable|string',
            'bcc' => 'nullable|string',
            'title' => 'sometimes|string',
            'subject' => 'sometimes|string',
            'message' => 'sometimes|string',
            'is_html' => 'boolean',
            'schedule' => 'nullable|string',
        ]);

        if (!$request->data('useScheduler')) {
            $data['schedule'] = null;
        }

        // Uppercase all fields except 'message'
        $upperData = $data;
        foreach ($upperData as $key => $value) {
            if ($key !== 'message' && is_string($value)) {
                $upperData[$key] = $this->uppercaseStrings([$key => $value])[$key];
            }
        }
        $notification->update($upperData);
        return response()->json($notification);
    }

    public function destroy(Request $request, $id)
    {
        $user = $this->validateDeletePassword($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        $notification = Notification::findOrFail($id);
        // If the model has a deleted_by column, set it
        if (Schema::hasColumn($notification->getTable(), 'deleted_by')) {
            $notification->deleted_by = $user ? $user->id : null;
            $notification->save();
        }
        $notification->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
